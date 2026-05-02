<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDebateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format_id'    => ['sometimes', 'integer', 'exists:debate_formats,id'],
            'motion_id'    => ['sometimes', 'integer', 'exists:motions,id'],
            'title'        => ['sometimes', 'string', 'max:255'],
            'description'  => ['sometimes', 'nullable', 'string'],
            'scheduled_at' => ['sometimes', 'date', 'after:now'],
            'tag'          => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
