<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'  => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20']
        ];
    }

    public function messages(): array
    {
        return [
            'name.max'  => 'الاسم يجب ألا يتجاوز 100 حرف. | Name must not exceed 100 characters.',
            'phone.max' => 'رقم الهاتف يجب ألا يتجاوز 20 حرفاً. | Phone must not exceed 20 characters.',
        ];
    }
}
