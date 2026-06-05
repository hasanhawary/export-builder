<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Str;

trait HelperTrait
{
    public function convertValue(mixed $object, string $column, mixed $type): mixed
    {
        $value = data_get($object, $column, '');

        return match ($type) {
            'date'      => empty($value) ? '' : Carbon::parse($value)->toDateString(),
            'datetime'  => empty($value) ? '' : Carbon::parse($value)->toDateTimeString(),
            'array'     => \is_array($value) ? implode(' , ', array_filter($value)) : $value,
            'int'       => (int) $value,
            'float'     => is_numeric($value) ? (float) $value : $value,
            'money'     => is_numeric($value) ? number_format((float) $value, 2, '.', '') : $value,
            'bool', 'boolean' => $value
                ? __($this->transFileKey('yes'))
                : __($this->transFileKey('no')),
            'classPath' => $this->resolveTrans(Str::afterLast((string) $value, '\\')),
            'text'      => $value,
            default     => (class_exists($type) && method_exists($type, 'resolve'))
                ? $type::resolve($value)
                : $value,
        };
    }

    public function flattenArray(array $array, ?string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}_{$key}" : $key;

            if (\is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Resolve a column name to a translated heading using the configured trans_file.
     * Falls back to the raw value when no translation exists.
     *
     * The trans_file config supports two formats:
     *  - 'export'          → package namespace: looks up 'export::export.{key}'
     *  - 'export::export'  → explicit namespaced form (same result)
     *  - 'api'             → host-app flat file: looks up 'api.{key}'
     */
    public function resolveTrans(mixed $trans = null): string
    {
        if ($trans === null || $trans === '') {
            return '';
        }

        $key       = Str::snake(Str::lower((string) $trans));
        $transFile = config('export.trans_file', 'export');

        // If trans_file has no '::' and no '.', it is a package namespace key.
        // The package registers translations as 'export' namespace with file 'export',
        // so the lookup key must be 'export::export.{key}'.
        if (! str_contains($transFile, '::') && ! str_contains($transFile, '.')) {
            $namespacedKey = "{$transFile}::{$transFile}.{$key}";
            $translated    = __($namespacedKey);
            if (! Str::startsWith($translated, "{$transFile}::")) {
                return $translated;
            }
        }

        // Flat file lookup (e.g. trans_file = 'api' → 'api.{key}')
        $flatKey    = "{$transFile}.{$key}";
        $translated = __($flatKey);

        return Str::startsWith($translated, "{$transFile}.") ? (string) $trans : $translated;
    }

    public function mergeKeyedArrays(array $arrays): array
    {
        return collect($arrays)
            ->mapWithKeys(fn ($item) => $item)
            ->toArray();
    }

    /**
     * Build a fully-qualified translation key using the configured trans_file.
     * Handles both package namespace ('export' → 'export::export.yes')
     * and flat host-app files ('api' → 'api.yes').
     */
    private function transFileKey(string $key): string
    {
        $transFile = config('export.trans_file', 'export');

        if (! str_contains($transFile, '::') && ! str_contains($transFile, '.')) {
            return "{$transFile}::{$transFile}.{$key}";
        }

        return "{$transFile}.{$key}";
    }
}
