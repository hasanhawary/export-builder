<?php

namespace HasanHawary\ExportBuilder;


use Carbon\Carbon;
use Illuminate\Support\Str;

trait HelperTrait
{
    /**
     * Convert column values to their respective data types.
     * @param mixed $object
     * @param string $column
     * @param mixed $type
     * @return mixed
     */
    public function convertValue(mixed $object, string $column, mixed $type): mixed
    {
        // Resolve value including nested (dot) paths using Laravel's data_get
        $value = function_exists('data_get') ? data_get($object, $column, '') : ($object?->$column ?? '');

        return match ($type) {
            'date' => empty($value) ? '' : Carbon::parse($value)->toDateString(),
            'datetime' => empty($value) ? '' : Carbon::parse($value)->toDateTimeString(),
            'array' => is_array($value) ? implode(" , ", array_filter($value)) : $value,
            'int' => (int)$value,
            'float' => is_numeric($value) ? (float)$value : $value,
            'money' => is_numeric($value) ? number_format((float)$value, 2, '.', '') : $value,
            'bool', 'boolean' => $value ? __('api.yes') : __('api.no'),
            'classPath' => $this->resolveTrans(Str::afterLast($value, '\\')),
            'text' => $value,
            default => (class_exists($type) && method_exists($type, 'resolve')) ? $type::resolve($value) : $value
        };
    }

    /**
     * @param array $array
     * @param string|null $prefix
     * @return array
     */
    public function flattenArray(array $array, ?string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = ($prefix) ? $prefix . '_' . $key : $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * @param mixed|null $trans
     * @return string
     */
    public function resolveTrans(mixed $trans = null): string
    {
        $key = Str::snake(Str::lower($trans));

        return Str::startsWith(__("api.$key"), 'api.')
            ? $trans
            : __("api.$key");
    }

    /**
     * Merges an array of associative arrays into a single associative array.
     *
     * @param array $arrays
     * @return array
     */
    public function mergeKeyedArrays(array $arrays): array
    {
        return collect($arrays)
            ->mapWithKeys(fn($item) => $item)
            ->toArray();
    }
}
