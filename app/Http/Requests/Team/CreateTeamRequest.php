<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:100'],
            'leader_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'debater')],
            'members'   => ['required', 'array', 'min:1'],
            'members.*' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'debater')],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $members  = $this->input('members', []);
            $leaderId = $this->input('leader_id');

            if ($leaderId && ! in_array((int) $leaderId, array_map('intval', $members))) {
                $validator->errors()->add(
                    'leader_id',
                    'القائد يجب أن يكون ضمن قائمة الأعضاء. | Leader must be included in the members list.'
                );
            }

            // Reject duplicate member IDs
            if (count($members) !== count(array_unique($members))) {
                $validator->errors()->add(
                    'members',
                    'قائمة الأعضاء تحتوي على معرّفات مكررة. | Members list contains duplicate IDs.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'اسم الفريق مطلوب. | Team name is required.',
            'leader_id.required' => 'قائد الفريق مطلوب. | Team leader is required.',
            'leader_id.exists'   => 'القائد يجب أن يكون متناظراً نشطاً. | Leader must be an existing debater.',
            'members.required'   => 'قائمة الأعضاء مطلوبة. | Members list is required.',
            'members.*.exists'   => 'أحد الأعضاء غير موجود أو ليس متناظراً. | One or more members are not valid debaters.',
        ];
    }
}
