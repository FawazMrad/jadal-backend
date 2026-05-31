<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                     => 'sometimes|required|string|max:255',
            'description'               => 'sometimes|nullable|string',
            'closes_at'                 => 'sometimes|nullable|date|after:now',
            'target_roles'              => 'sometimes|required|array',
            'target_roles.*'            => 'in:debater,trainer,judge,admin',
            'team_ids'                  => 'sometimes|array',
            'team_ids.*'                => 'exists:teams,id',
            'questions'                 => 'sometimes|array|min:1',
            'questions.*.question_text' => 'required_with:questions|string',
            'questions.*.type'          => 'required_with:questions|in:mcq,rating,open_text',
            'questions.*.options'       => 'nullable|array',
            'questions.*.order_index'   => 'nullable|integer|min:0',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('questions')) {
                foreach ($this->questions as $i => $q) {
                    if (($q['type'] ?? null) === 'mcq') {
                        $opts = $q['options'] ?? [];
                        if (! is_array($opts) || count($opts) < 2) {
                            $validator->errors()->add(
                                "questions.{$i}.options",
                                'MCQ questions require at least 2 options.'
                            );
                        }
                    }
                }
            }
        });
    }
}
