<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;

class TeamRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'team_id'             => ['required', 'integer', 'exists:teams,id'],
            'speaker_user_ids'    => ['required', 'array', 'size:3'],
            'speaker_user_ids.*'  => ['required', 'integer', 'distinct', 'exists:users,id'],
        ];
    }
}
