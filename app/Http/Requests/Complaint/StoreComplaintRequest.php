<?php

namespace App\Http\Requests\Complaint;

use Illuminate\Foundation\Http\FormRequest;

class StoreComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string'],
            'debate_id'   => ['sometimes', 'nullable', 'integer', 'exists:debates,id'],
            // Who the complaint is about, and in what capacity. Optional (old
            // clients keep working), but they come as a pair — a target user
            // without a role can't be bucketed by the accountability stats,
            // and a role without a user identifies nobody. No `sometimes`
            // here: required_with must run even when the field is absent.
            'target_user_id' => ['nullable', 'integer', 'exists:users,id', 'required_with:target_role'],
            'target_role'    => ['nullable', 'string', 'in:debater,trainer,judge,chair', 'required_with:target_user_id'],
        ];
    }
}
