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
            'winning_side'               => ['required', Rule::in(['proposition', 'opposition', 'draw'])],
            'stage_scores'               => ['required', 'array', 'min:1'],
            'stage_scores.*.stage_order' => ['required', 'integer', 'min:1'],
            'stage_scores.*.score'       => ['required', 'integer', 'min:0', 'max:100'],
            'summary_notes'              => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $debate      = $this->route('debate');
            $stageScores = $this->input('stage_scores', []);
            $phaseCount  = $debate?->phases()->count() ?? 0;

            if ($phaseCount > 0 && count($stageScores) !== $phaseCount) {
                $v->errors()->add(
                    'stage_scores',
                    "Must provide exactly {$phaseCount} stage scores (one per stage)."
                );
            }
        });
    }
}
