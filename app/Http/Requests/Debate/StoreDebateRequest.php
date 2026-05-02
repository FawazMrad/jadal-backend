<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;

class StoreDebateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format_id'    => ['required', 'integer', 'exists:debate_formats,id'],
            'motion_id'    => ['required', 'integer', 'exists:motions,id'],
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['sometimes', 'nullable', 'string'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'tag'          => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
