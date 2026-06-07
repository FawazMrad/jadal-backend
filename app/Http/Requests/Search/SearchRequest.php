<?php

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q')) {
            $this->merge(['q' => trim($this->q)]);
        }
    }

    public function rules(): array
    {
        return [
            'q'          => ['required', 'string', 'min:2', 'max:100'],
            'per_page'   => ['sometimes', 'integer', 'min:1', 'max:50'],
            'users_page' => ['sometimes', 'integer', 'min:1'],
            'teams_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
