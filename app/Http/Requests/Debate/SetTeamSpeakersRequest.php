<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetTeamSpeakersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'side'             => ['required', Rule::in(['proposition', 'opposition'])],
            'speaker_user_ids' => ['required', 'array', 'size:3'],
            'speaker_user_ids.*' => ['required', 'integer', 'exists:users,id', 'distinct'],
        ];
    }
}
