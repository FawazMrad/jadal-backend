<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterDebateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Backward-compat: older clients sent `role` (debater|judge). Map it onto the
     * new `as` field when `as` is absent.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('as') && $this->filled('role')) {
            $this->merge(['as' => $this->input('role')]);
        }
    }

    public function rules(): array
    {
        return [
            'as'      => ['required', Rule::in(['debater', 'judge', 'team'])],
            'team_id' => ['required_if:as,team', 'nullable', 'integer', 'exists:teams,id'],
        ];
    }
}
