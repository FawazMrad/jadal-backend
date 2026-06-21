<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;

class LinkDebateTeamsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proposition_team_id' => ['required', 'integer', 'exists:teams,id', 'different:opposition_team_id'],
            'opposition_team_id'  => ['required', 'integer', 'exists:teams,id'],
        ];
    }
}
