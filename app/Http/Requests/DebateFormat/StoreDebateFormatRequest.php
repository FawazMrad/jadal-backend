<?php

namespace App\Http\Requests\DebateFormat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDebateFormatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                          => ['required', 'string', 'max:255'],
            'description'                   => ['sometimes', 'nullable', 'string'],
            'phase_config'                  => ['required', 'array', 'min:1'],
            'phase_config.*.name'           => ['required', 'string'],
            'phase_config.*.order_index'    => ['required', 'integer', 'min:0'],
            'phase_config.*.duration_seconds' => ['required', 'integer', 'min:1'],
            'phase_config.*.role'           => ['required', Rule::in(['proposition', 'opposition', 'both', 'judge'])],
        ];
    }
}
