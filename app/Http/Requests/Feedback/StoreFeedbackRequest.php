<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'debate_id'  => ['required', 'integer', 'exists:debates,id'],
            'to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'type'       => ['required', Rule::in(['judge_to_debater', 'trainer_to_debater', 'debater_on_session'])],
            'content'    => ['required', 'string'],
            'scores'     => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $user = $this->user();
            $type = $this->input('type');

            if ($type === 'judge_to_debater') {
                if ($user->role !== 'judge') {
                    $v->errors()->add('type', 'Only judges can submit judge_to_debater feedback.');
                }
                if (empty($this->input('to_user_id'))) {
                    $v->errors()->add('to_user_id', 'to_user_id is required for judge_to_debater feedback.');
                }
            }

            if ($type === 'trainer_to_debater') {
                if ($user->role !== 'trainer') {
                    $v->errors()->add('type', 'Only trainers can submit trainer_to_debater feedback.');
                }
                if (empty($this->input('to_user_id'))) {
                    $v->errors()->add('to_user_id', 'to_user_id is required for trainer_to_debater feedback.');
                }
            }

            if ($type === 'debater_on_session') {
                if ($user->role !== 'debater') {
                    $v->errors()->add('type', 'Only debaters can submit debater_on_session feedback.');
                }
                if (! empty($this->input('to_user_id'))) {
                    $v->errors()->add('to_user_id', 'to_user_id must be omitted for debater_on_session feedback.');
                }
            }
        });
    }
}
