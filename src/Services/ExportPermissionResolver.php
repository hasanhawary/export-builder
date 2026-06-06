<?php

namespace HasanHawary\ExportBuilder\Services;

use HasanHawary\ExportBuilder\Models\ExportFile;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

class ExportPermissionResolver
{
    /**
     * Whether the user may trigger a direct (synchronous) export.
     */
    public function canExport(?Authenticatable $user, array $filters): bool
    {
        return $this->check($user, function (Authenticatable $u) use ($filters): bool {
            $page       = (string) ($filters['page'] ?? '');
            $configured = config("export.module.permissions.pages.{$page}.export");

            return $configured
                ? $this->userCan($u, $configured)
                : $this->userCan($u, config('export.module.permissions.abilities.export', 'export'));
        });
    }

    /**
     * Whether the user may create a queued export record.
     */
    public function canCreateQueued(?Authenticatable $user, array $filters): bool
    {
        return $this->check($user, function (Authenticatable $u) use ($filters): bool {
            $page       = (string) ($filters['page'] ?? '');
            $configured = config("export.module.permissions.pages.{$page}.queue");

            return $configured
                ? $this->userCan($u, $configured)
                : $this->userCan($u, config('export.module.permissions.abilities.queue', 'create-export-file'));
        });
    }

    /**
     * Whether the user may list export records (has view-all OR view-own).
     */
    public function canList(?Authenticatable $user): bool
    {
        return $this->check($user, function (Authenticatable $u): bool {
            return $this->userCan($u, config('export.module.permissions.abilities.view_all', 'view-all-export-file'))
                || $this->userCan($u, config('export.module.permissions.abilities.view_own', 'view-own-export-file'));
        });
    }

    /**
     * Apply the correct visibility scope to an ExportFile query for a given user.
     *
     * - permissions disabled → no restriction
     * - view-all             → no restriction
     * - view-own             → only own records
     * - neither              → empty result set
     *
     * Single owner of list-scoping logic — ExportJobController::index() must call this.
     */
    public function scopeForUser(Builder $query, ?Authenticatable $user): Builder
    {
        if (! $this->enabled()) {
            return $query;
        }

        $user = $this->resolveUser($user);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->userCan($user, config('export.module.permissions.abilities.view_all', 'view-all-export-file'))) {
            return $query;
        }

        if ($this->userCan($user, config('export.module.permissions.abilities.view_own', 'view-own-export-file'))) {
            return $query->where('created_by', $user->getAuthIdentifier());
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Whether the user may view a specific export record.
     */
    public function canView(?Authenticatable $user, ExportFile $exportFile): bool
    {
        return $this->check($user, function (Authenticatable $u) use ($exportFile): bool {
            if ($this->userCan($u, config('export.module.permissions.abilities.view_all', 'view-all-export-file'))) {
                return true;
            }

            return $this->userCan($u, config('export.module.permissions.abilities.view_own', 'view-own-export-file'))
                && (int) $exportFile->created_by === (int) $u->getAuthIdentifier();
        });
    }

    /**
     * Whether the user may delete a specific export record.
     * Requires the delete ability AND the ability to view the record.
     */
    public function canDelete(?Authenticatable $user, ExportFile $exportFile): bool
    {
        return $this->check($user, function (Authenticatable $u) use ($exportFile): bool {
            return $this->userCan($u, config('export.module.permissions.abilities.delete', 'delete-export-file'))
                && $this->canView($u, $exportFile);
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Single entry-point for all permission checks.
     *
     * Handles the enabled-guard + user-resolution + null-check triple that would
     * otherwise repeat in every public method. When permissions are disabled the
     * check passes immediately (returns true). When no user is available it
     * fails immediately (returns false).
     *
     * @param  callable(Authenticatable): bool  $logic
     */
    private function check(?Authenticatable $user, callable $logic): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $user = $this->resolveUser($user);

        if (! $user) {
            return false;
        }

        return $logic($user);
    }

    private function enabled(): bool
    {
        return (bool) config('export.module.permissions.enabled', false);
    }

    private function resolveUser(?Authenticatable $user): ?Authenticatable
    {
        return $user ?? auth()->user();
    }

    private function userCan(Authenticatable $user, string|array $abilities): bool
    {
        foreach ((array) $abilities as $ability) {
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo($ability)) {
                return true;
            }

            if (method_exists($user, 'can') && $user->can($ability)) {
                return true;
            }
        }

        return false;
    }
}
