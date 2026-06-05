<?php

namespace HasanHawary\ExportBuilder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page'          => ['required', 'string'],
            'format'        => ['nullable', 'string', Rule::in(['xlsx', 'xls', 'csv', 'pdf'])],
            'start'         => ['nullable', 'date'],
            'end'           => ['nullable', 'date', 'after_or_equal:start'],
            'columns'       => ['sometimes', 'array'],
            'related'       => ['sometimes', 'array'],
            'related_type'  => ['sometimes', 'string'],
            'filename'      => ['sometimes', 'string', 'max:255'],
            'timestamp'     => ['sometimes', 'string', 'max:50'],
            'advanced'      => ['sometimes', 'array'],
            'advanced.*.key'   => ['required_with:advanced', 'string'],
            'advanced.*.value' => ['required_with:advanced'],
        ];
    }
}
