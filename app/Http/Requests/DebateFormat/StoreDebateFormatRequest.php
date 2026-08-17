<?php

namespace App\Http\Requests\DebateFormat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `phase_config` is a timing OBJECT, never a list of phases — the phases
 * themselves are generated from these values by DebateFormat::deriveStages().
 * See ValidatesPhaseConfig for why the shape and reply-time rules are shared
 * with the update request rather than restated here.
 */
class StoreDebateFormatRequest extends FormRequest
{
    use ValidatesPhaseConfig;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],

            'phase_config'                              => ['required', 'array', $this->phaseConfigShapeRule()],
            'phase_config.speech_time_seconds'          => ['required', 'integer', 'min:60', 'max:1800'],
            'phase_config.has_reply_speech'             => ['required', 'boolean'],
            'phase_config.reply_time_seconds'           => $this->replyTimeRules(),
            'phase_config.motion_reveal_offset_hours'   => ['required', 'numeric', 'min:0', 'max:168'],
            'phase_config.prep_rooms_open_offset_hours' => ['required', 'numeric', 'min:0', 'max:24'],
        ];
    }
}
