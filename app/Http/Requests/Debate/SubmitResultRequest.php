<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'winning_side'  => ['required', Rule::in(['proposition', 'opposition', 'draw'])],
            'scores'        => ['required', 'array'],
            'summary_notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
