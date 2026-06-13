<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignParticipantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'participants'           => ['required', 'array', 'min:1'],
            'participants.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'participants.*.role'    => ['required', Rule::in(['debater', 'trainer', 'judge', 'viewer'])],
            'participants.*.side'    => ['required', Rule::in(['proposition', 'opposition', 'judge', 'trainer', 'viewer'])],
            'participants.*.team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'participants.*.is_chair'   => ['sometimes', 'boolean'],
            'participants.*.judge_order' => ['sometimes', 'nullable', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $participants = $this->input('participants', []);
            $chairCount   = 0;

            foreach ($participants as $i => $p) {
                if (! empty($p['is_chair'])) {
                    $chairCount++;
                    if (($p['role'] ?? '') !== 'judge') {
                        $v->errors()->add("participants.{$i}.is_chair", 'Only judges can be assigned as chair.');
                    }
                }
            }

            if ($chairCount > 1) {
                $v->errors()->add('participants', 'Only one participant can be the chair judge.');
            }
        });
    }
}
