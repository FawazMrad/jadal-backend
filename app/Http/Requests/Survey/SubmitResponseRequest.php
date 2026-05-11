<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;

class SubmitResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'answers' => ['required', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->any()) {
                return;
            }

            /** @var \App\Models\Survey $survey */
            $survey = $this->route('survey');
            if (! $survey) {
                return;
            }

            $survey->loadMissing('questions');

            $requiredIds = $survey->questions->pluck('id')->all();
            $submittedIds = array_keys($this->input('answers', []));

            foreach ($requiredIds as $qId) {
                if (! in_array($qId, $submittedIds, true)) {
                    $v->errors()->add("answers.{$qId}", "Answer for question ID {$qId} is required.");
                }
            }
        });
    }
    }
