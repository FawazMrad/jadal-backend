<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name'  => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'string', 'email', 'max:255', "unique:users,email,{$userId}"],
            'role'  => ['sometimes', 'in:debater,trainer,judge,admin'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'البريد الإلكتروني مستخدم بالفعل. | Email already exists.',
            'role.in'      => 'الدور غير صالح. | Role must be: debater, trainer, judge, or admin.',
        ];
    }
}
