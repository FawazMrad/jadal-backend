<?php

namespace App\Http\Requests\Motion;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text'            => ['sometimes', 'string'],
            'framework_ids'   => ['sometimes', 'array'],
            'framework_ids.*' => ['integer', 'exists:motion_frameworks,id'],
        ];
    }
}
