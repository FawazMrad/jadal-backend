<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reusable request for GET listing endpoints that accept an optional `?search`
 * term. It validates only the search param and leaves every existing query
 * parameter (status, role, page, etc.) untouched and accessible as before.
 */
class SearchListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('search')) {
            $this->merge(['search' => trim((string) $this->input('search'))]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|min:2|max:100',
        ];
    }
}
