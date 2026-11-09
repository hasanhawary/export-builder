<?php

namespace HasanHawary\ExportBuilder\Support;

use Illuminate\Support\Str;

/**
 * Registers the export-file permissions with hasanhawary/permission-manager,
 * so host projects do not have to add them to config/roles.php by hand.
 *
 * permission-manager builds names as "{operation}-export-file" for the "ExportFile" entry,
 * so the operations are taken from the configured abilities that end with "-export-file".
 */
class PermissionManagerOperations
{
    public const NAME = 'ExportFile';

    private const SUFFIX = '-export-file';

    public function register(): void
    {
        if (! $this->shouldRegister()) {
            return;
        }

        $operations = $this->operations();
        $existing   = (array) config('roles.additional_operations', []);

        // The project's own entry always wins.
        $alreadyDefined = collect($existing)->contains(fn ($entry) => ($entry['name'] ?? null) === self::NAME);

        if ($operations === [] || $alreadyDefined) {
            return;
        }

        $existing[] = ['name' => self::NAME, 'operations' => $operations];

        config(['roles.additional_operations' => $existing]);
    }

    /**
     * e.g. "view-own-export-file" → "view-own". Abilities without the suffix (like "export") are skipped.
     */
    public function operations(): array
    {
        return collect(config('export.module.permissions.abilities', []))
            ->flatten()
            ->filter(fn ($ability) => is_string($ability) && Str::endsWith($ability, self::SUFFIX))
            ->map(fn (string $ability) => Str::beforeLast($ability, self::SUFFIX))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function shouldRegister(): bool
    {
        return config('export.module.enabled', true)
            && config('export.module.permissions.register', true)
            && $this->permissionManagerInstalled();
    }

    protected function permissionManagerInstalled(): bool
    {
        return class_exists('HasanHawary\\PermissionManager\\PermissionManagerServiceProvider');
    }
}
