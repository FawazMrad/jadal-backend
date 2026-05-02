<?php

namespace App\Http\Requests\Evaluation;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'debater_id' => ['required', 'integer', 'exists:users,id'],
            'debate_id'  => ['sometimes', 'nullable', 'integer', 'exists:debates,id'],
            'notes'      => ['required', 'string'],
            'scores'     => ['required', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $debaterId = $this->input('debater_id');
            if ($debaterId) {
                $debater = User::find($debaterId);
                if (! $debater || $debater->role !== 'debater') {
                    $v->errors()->add('debater_id', 'The specified user must have the debater role.');
                }
            }
        });
    }
}
