<?php

namespace App\Http\Requests\Survey;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_text' => ['required', 'string'],
            'type'          => ['required', Rule::in(['mcq', 'rating', 'open_text'])],
            'options'       => ['nullable', 'array'],
            'options.*'     => ['string'],
            'order_index'   => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('type') === 'mcq') {
                $options = $this->input('options', []);
                if (empty($options)) {
                    $v->errors()->add('options', 'Options are required for MCQ questions.');
                } elseif (count($options) < 2) {
                    $v->errors()->add('options', 'MCQ questions must have at least 2 options.');
                }
            }
        });
    }
}
