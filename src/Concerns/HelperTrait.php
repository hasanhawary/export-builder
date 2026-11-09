<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Carbon\Carbon;
use HasanHawary\ExportBuilder\Support\ExportHelper;
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
            // Same lookup as headings (package namespace first): a flat __('export.yes') only
            // worked when the app had published the lang files, otherwise cells read "export.yes".
            'bool', 'boolean' => $value ? $this->resolveTrans('yes') : $this->resolveTrans('no'),
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

        // Lowercase first so 'ID' → 'id' (not 'i_d'); fall back to the original text.
        $key = Str::snake(Str::lower((string) $trans));

        return ExportHelper::lookup($key, (string) config('export.trans_file', 'export')) ?? (string) $trans;
    }

    public function mergeKeyedArrays(array $arrays): array
    {
        return collect($arrays)
            ->mapWithKeys(fn ($item) => $item)
            ->toArray();
    }

}
