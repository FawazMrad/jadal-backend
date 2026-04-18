<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_ids'   => ['required', 'array', 'min:1'],
            'member_ids.*' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'debater')],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $ids = $this->input('member_ids', []);

            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add(
                    'member_ids',
                    'قائمة الأعضاء تحتوي على معرّفات مكررة. | Member IDs list contains duplicates.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'member_ids.required'   => 'قائمة الأعضاء مطلوبة. | Member IDs are required.',
            'member_ids.*.integer'  => 'كل معرّف يجب أن يكون رقماً صحيحاً. | Each member ID must be an integer.',
            'member_ids.*.exists'   => 'أحد المعرّفات غير موجود أو ليس متناظراً. | One or more IDs do not belong to a debater.',
        ];
    }
}
