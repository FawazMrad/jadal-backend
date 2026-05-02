<?php

namespace App\Http\Requests\DebateFormat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDebateFormatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                            => ['sometimes', 'string', 'max:255'],
            'description'                     => ['sometimes', 'nullable', 'string'],
            'phase_config'                    => ['sometimes', 'array', 'min:1'],
            'phase_config.*.name'             => ['required_with:phase_config', 'string'],
            'phase_config.*.order_index'      => ['required_with:phase_config', 'integer', 'min:0'],
            'phase_config.*.duration_seconds' => ['required_with:phase_config', 'integer', 'min:1'],
            'phase_config.*.role'             => ['required_with:phase_config', Rule::in(['proposition', 'opposition', 'both', 'judge'])],
        ];
    }
}
