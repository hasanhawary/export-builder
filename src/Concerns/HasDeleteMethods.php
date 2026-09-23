<?php

namespace HasanHawary\ExportBuilder\Concerns;

use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use function eb_resolveTrans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

trait HasDeleteMethods
{
    public string $model = ExportFile::class;

    /**
     * Action guards (delete|restore|force)
     */
    protected array $deleteGuards = [];

    protected bool $useDeletePolicy = true;

    protected array $beforeDeleteCallbacks = [];

    protected array $afterDeleteCallbacks = [];

    /*
    |--------------------------------------------------------------------------
    | Configuration Methods
    |--------------------------------------------------------------------------
    */
    protected function setDeleteModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    protected function enableDeletePolicy(bool $state = true): self
    {
        $this->useDeletePolicy = $state;

        return $this;
    }

    /**
     * Set guards for an action (except callable or array of callables)
     */
    protected function setDeleteGuards(string $action, callable|array $guards): self
    {
        $guards = is_callable($guards) ? [$guards] : $guards;
        $this->deleteGuards[$action] = array_merge($this->deleteGuards[$action] ?? [], $guards);

        return $this;
    }

    protected function beforeDelete(string $action, callable|array $callback): self
    {
        $callback = is_callable($callback) ? [$callback] : $callback;
        $this->beforeDeleteCallbacks[$action] = array_merge($this->beforeDeleteCallbacks[$action] ?? [], $callback);

        return $this;
    }

    protected function afterDelete(string $action, callable|array $callback): self
    {
        $callback = is_callable($callback) ? [$callback] : $callback;
        $this->afterDeleteCallbacks[$action] = array_merge($this->afterDeleteCallbacks[$action] ?? [], $callback);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Public Methods
    |--------------------------------------------------------------------------
    */
    public function destroy(): JsonResponse
    {
        return $this->handleDelete('delete');
    }

    public function restore(): JsonResponse
    {
        return $this->handleDelete('restore');
    }

    public function forceDelete(): JsonResponse
    {
        return $this->handleDelete('force');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    protected function handleDelete(string $action, ?array $ids = null): JsonResponse
    {
        $ids ??= $this->resolveDeleteIds();
        $query = $this->buildDeleteQuery($action, $ids);
        $models = $query->get();

        if ($models->isEmpty()) {
            abort(404, eb_resolveTrans('record_not_found'));
        }

        foreach ($models as $model) {
            // Policy
            if ($this->useDeletePolicy) {
                $this->applyDeleteAuthorize($action, $model);
            }

            // Custom Guards
            if (! $this->passesDeleteGuards($action, $model)) {
                abort(403, eb_resolveTrans("not_allowed_to_{$action}"));
            }

            if (in_array($action, ['delete', 'force'], true)) {
                $this->guardLinkedRelations($model);
            }
        }

        foreach ($models as $model) {
            // Before callbacks
            $this->runDeleteCallbacks($this->beforeDeleteCallbacks[$action] ?? [], $model);

            // Execute action
            $this->executeDelete($model, $action);

            // After callbacks
            $this->runDeleteCallbacks($this->afterDeleteCallbacks[$action] ?? [], $model);
        }

        return response()->json(['message' => eb_resolveTrans(
            $action === 'restore' ? 'restored_successfully' : 'export_deleted_successfully'
        )]);
    }

    protected function applyDeleteAuthorize(string $action, Model $model): void
    {
        if ($action === 'delete' && $model instanceof ExportFile) {
            abort_unless(
                app(ExportPermissionResolver::class)->canDelete(request()->user(), $model),
                403,
                eb_resolveTrans('not_allowed_to_delete')
            );

            return;
        }

        $ability = match ($action) {
            'force' => 'forceDelete',
            default => $action,
        };

        Gate::authorize($ability, $model);
    }

    protected function passesDeleteGuards(string $action, Model $model): bool
    {
        foreach ($this->deleteGuards[$action] ?? [] as $guard) {
            if (is_callable($guard) && ! $guard($model)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prevent deleting a record while it is still referenced by any of the
     * relations the model itself declares as delete-blocking.
     *
     * A model opts in by defining `preventDeleteRelations(): array`. Two shapes
     * are supported:
     *   - a plain list of relation names: ['contracts', 'users']
     *   - a map of relation => validation message key:
     *       ['contracts' => 'not_allowed_to_delete_linked_contract']
     */
    protected function guardLinkedRelations(Model $model): void
    {
        if (! method_exists($model, 'preventDeleteRelations')) {
            return;
        }

        foreach ($model->preventDeleteRelations() as $relation => $messageKey) {
            if (is_int($relation)) {
                $relation = $messageKey;
                $messageKey = 'not_allowed_to_delete_linked';
            }

            if ($model->{$relation}()->exists()) {
                abort(403, eb_resolveTrans($messageKey));
            }
        }
    }

    protected function executeDelete(Model $model, string $action): void
    {
        if ($action === 'delete' && $model instanceof ExportFile) {
            app(ExportFileService::class)->delete($model);

            return;
        }

        match ($action) {
            'restore' => $model->restore(),
            'force' => method_exists($model, 'forceDelete')
                ? $model->forceDelete()
                : $model->delete(),
            default => $model->delete()
        };
    }

    protected function buildDeleteQuery(string $action, array $ids)
    {
        $query = $this->model::query();

        if (in_array($action, ['restore', 'force'], true) && $this->supportsSoftDeletes()) {
            $query->onlyTrashed();
        }

        return $query->whereKey($ids);
    }

    protected function supportsSoftDeletes(): bool
    {
        return in_array(
            SoftDeletes::class,
            class_uses_recursive($this->model),
            true
        );
    }

    protected function resolveDeleteIds(): array
    {
        // The export endpoint always targets its route record, even if a body supplies IDs.
        $exportFile = request()->route('exportFile');
        if (is_a($this->model, ExportFile::class, true) && $exportFile !== null) {
            return [$exportFile instanceof Model ? $exportFile->getKey() : $exportFile];
        }

        $ids = request()->input('ids')
            ?? request()->input('id');

        if (! $ids) {
            $routeParams = request()->route()?->parameters();
            if (! empty($routeParams)) {
                $ids = array_values($routeParams)[0]; // take the first parameter
            }
        }

        return Arr::wrap($ids); // always return as array
    }

    protected function runDeleteCallbacks(array $callbacks, Model $model): void
    {
        foreach ($callbacks as $callback) {
            $callback($model);
        }
    }
}
