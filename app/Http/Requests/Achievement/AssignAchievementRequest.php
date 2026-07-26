<?php

namespace App\Http\Requests\Achievement;

use Illuminate\Foundation\Http\FormRequest;

class AssignAchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'achievement_id' => ['required', 'integer', 'exists:achievements,id'],
        ];
    }
}
