<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_text' => ['sometimes', 'string'],
            'type'          => ['sometimes', Rule::in(['mcq', 'rating', 'open_text'])],
            'options'       => ['sometimes', 'nullable', 'array'],
            'options.*'     => ['string'],
            'order_index'   => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // Only enforce options requirement when type is explicitly being set to mcq in this request
            if ($this->input('type') === 'mcq' && $this->has('options')) {
                $options = $this->input('options', []);
                if (empty($options) || count($options) < 2) {
                    $v->errors()->add('options', 'MCQ questions must have at least 2 options.');
                }
            }
        });
    }
}
