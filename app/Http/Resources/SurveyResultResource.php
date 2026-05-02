<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $responses = $this->responses ?? collect();
        $questions = $this->questions ?? collect();

        $aggregated = $questions->sortBy('order_index')->values()->map(function ($question) use ($responses) {
            $answers = $responses->map(function ($response) use ($question) {
                $data = is_array($response->answers) ? $response->answers : [];
                return $data[(string) $question->id] ?? null;
            })->filter()->values();

            $aggregate = match ($question->type) {
                'mcq' => [
                    'distribution' => $answers->countBy()->all(),
                    'total'        => $answers->count(),
                ],
                'rating' => [
                    'average' => $answers->count() > 0 ? round((float) $answers->avg(), 2) : null,
                    'total'   => $answers->count(),
                ],
                'open_text' => [
                    'answers' => $answers->values()->all(),
                    'total'   => $answers->count(),
                ],
                default => ['total' => 0],
            };

            return [
                'id'            => $question->id,
                'question_text' => $question->question_text,
                'type'          => $question->type,
                'options'       => $question->options,
                'aggregate'     => $aggregate,
            ];
        });

        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'description'     => $this->description,
            'total_responses' => $responses->count(),
            'questions'       => $aggregated,
            'responses'       => $responses->map(fn($r) => [
                'id'           => $r->id,
                'user'         => $r->relationLoaded('user') ? new UserResource($r->user) : ['id' => $r->user_id],
                'answers'      => $r->answers,
                'submitted_at' => $r->created_at?->toIso8601String(),
            ]),
        ];
    }
}
