<?php

namespace HasanHawary\ExportBuilder\Http\Requests;

use HasanHawary\ExportBuilder\ExportBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 'AR', 'ar_EG', 'ar-eg' → 'ar' (when supported). Anything that cannot be mapped to a
     * supported locale is left as sent, so the 'lang' rule rejects it with a 422.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('lang')) {
            return;
        }

        $lang = $this->input('lang');

        if ($lang === null || (is_string($lang) && trim($lang) === '')) {
            $this->merge(['lang' => null]);

            return;
        }

        if (is_string($lang) && ($locale = ExportBuilder::normalizeLocale($lang)) !== null) {
            $this->merge(['lang' => $locale]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page'          => ['required', 'string', 'max:255'],
            'lang'          => ['nullable', 'string', Rule::in(ExportBuilder::supportedLocales())],
            'format'        => ['nullable', 'string', Rule::in(['xlsx', 'xls', 'csv', 'pdf'])],
            'start'         => ['nullable', 'date'],
            'end'           => ['nullable', 'date', 'after_or_equal:start'],
            'columns'       => ['sometimes', 'array'],
            'columns.*'     => ['string'],
            'related'       => ['sometimes', 'array'],
            'related.*'     => ['string'],
            'related_type'  => ['sometimes', 'string'],
            'filename'      => ['sometimes', 'string', 'max:255'],
            'timestamp'     => ['sometimes', 'string', 'max:50'],
            'advanced'      => ['sometimes', 'array'],
            'advanced.*.key'   => ['required_with:advanced', 'string'],
            'advanced.*.value' => ['required_with:advanced'],
        ];
    }
}
