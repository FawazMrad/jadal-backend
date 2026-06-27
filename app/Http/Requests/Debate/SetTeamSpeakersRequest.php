<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetTeamSpeakersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hasReply = $this->debateHasReplySpeech();

        return [
            'side'               => ['required', Rule::in(['proposition', 'opposition'])],
            // Ordered speaking assignment, one entry per speaking slot. Duplicates
            // ARE allowed so a single debater can cover multiple slots (multi-role
            // teams, e.g. a 2-person team filling 3 slots as [A, B, A]).
            'speaker_user_ids'   => ['required', 'array', 'size:' . \App\Models\DebateFormat::SPEAKERS_PER_SIDE],
            'speaker_user_ids.*' => ['required', 'integer', 'exists:users,id'],
            'reply_speaker_user_id' => [
                $hasReply ? 'required' : 'prohibited',
                'integer',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (! $this->debateHasReplySpeech()) {
                return;
            }

            $replyId  = $this->input('reply_speaker_user_id');
            $speakers = $this->input('speaker_user_ids', []);

            if ($replyId === null) {
                return; // handled by 'required' rule
            }

            // Reply speaker must be one of the three speakers.
            if (! in_array((int) $replyId, array_map('intval', $speakers), true)) {
                $v->errors()->add('reply_speaker_user_id', 'Reply speaker must be one of the three speakers.');
                return;
            }

            // Reply speaker must be slot 1 or slot 2 (index 0 or 1), never slot 3.
            $index = array_search((int) $replyId, array_map('intval', $speakers), true);
            if ($index === 2) {
                $v->errors()->add(
                    'reply_speaker_user_id',
                    'Reply speaker must be the first or second speaker (slot 1 or 2), not the third.'
                );
            }
        });
    }

    private function debateHasReplySpeech(): bool
    {
        $debate = $this->route('debate');
        if (! $debate) {
            return false;
        }

        $config = $debate->format?->phase_config ?? [];

        return (bool) ($config['has_reply_speech'] ?? false);
    }
}
