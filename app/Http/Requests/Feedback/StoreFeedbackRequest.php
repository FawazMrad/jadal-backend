<?php

namespace App\Http\Requests\Feedback;

use App\Models\Debate;
use App\Models\DebateParticipant;
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
        $isRating = $this->isRatingType();

        return [
            'debate_id'  => ['required', 'integer', 'exists:debates,id'],
            'to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'type'       => ['required', Rule::in([
                'judge_to_debater',
                'trainer_to_debater',
                'debater_on_session',
                'rating_debate',
                'rating_judgement',
            ])],
            // Notes are optional for ratings, required for the classic feedback types.
            'content'        => [$isRating ? 'nullable' : 'required', 'string', 'max:2000'],
            'scores'         => ['sometimes', 'nullable', 'array'],
            'scores.rating'  => [$isRating ? 'required' : 'nullable', 'integer', 'min:1', 'max:5'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $user = $this->user();
            $type = $this->input('type');

            // ── Classic feedback types (unchanged) ────────────────────────────
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

            // ── Rating types ─────────────────────────────────────────────────
            if (! $this->isRatingType()) {
                return;
            }

            $debate = Debate::find($this->input('debate_id'));
            if (! $debate) {
                return; // exists rule already failed
            }

            // Only after the debate is completed AND the result has been revealed.
            if ($debate->status !== 'completed' || $debate->result_revealed_at === null) {
                $v->errors()->add('type', 'Ratings can only be submitted after the result is revealed.');
            }

            // Only participants of the debate may rate.
            $isParticipant = DebateParticipant::where('debate_id', $debate->id)
                ->where('user_id', $user->id)
                ->exists();
            if (! $isParticipant) {
                $v->errors()->add('type', 'Only participants of this debate can submit ratings.');
            }

            if ($type === 'rating_debate') {
                if (! empty($this->input('to_user_id'))) {
                    $v->errors()->add('to_user_id', 'to_user_id must be omitted for rating_debate.');
                }
            }

            if ($type === 'rating_judgement') {
                $toUserId = $this->input('to_user_id');
                if (empty($toUserId)) {
                    $v->errors()->add('to_user_id', 'to_user_id is required for rating_judgement.');
                } else {
                    $isJudge = DebateParticipant::where('debate_id', $debate->id)
                        ->where('user_id', $toUserId)
                        ->where('role', 'judge')
                        ->exists();
                    if (! $isJudge) {
                        $v->errors()->add('to_user_id', 'The rated user must be a judge of this debate.');
                    }
                }
            }
        });
    }

    private function isRatingType(): bool
    {
        return in_array($this->input('type'), ['rating_debate', 'rating_judgement'], true);
    }
}
