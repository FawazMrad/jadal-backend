<?php

namespace App\Http\Requests\Motion;

use Illuminate\Foundation\Http\FormRequest;

class StoreMotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text'            => ['required', 'string'],
            'framework_ids'   => ['sometimes', 'array'],
            'framework_ids.*' => ['integer', 'exists:motion_frameworks,id'],
        ];
    }
}
