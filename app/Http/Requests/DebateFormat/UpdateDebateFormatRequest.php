<?php

namespace App\Http\Requests\DebateFormat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `phase_config` is a single JSON column and an update REPLACES it wholesale —
 * there is no per-key merge. That made the previous rule set unsafe.
 *
 * Every inner key used to carry `sometimes`, which means "validate only if this
 * key is present". A payload sending `phase_config` as a LIST of phase objects
 * (the legacy shape some rows still hold, and the shape the admin dashboard was
 * reading back and echoing) contains none of those keys, so every inner rule was
 * skipped, only `['sometimes','array']` ran — and a list IS a PHP array — so the
 * request validated and overwrote the column with a shape the backend cannot
 * consume. `DebateFormat::deriveStages()` then reads
 * `phase_config['speech_time_seconds']` on that row, gets null, and the NOT NULL
 * `debate_phases.duration_seconds` insert throws: the debate can never start.
 *
 * `required_with:phase_config` makes every timing key mandatory the moment
 * `phase_config` is sent at all. Omitting `phase_config` entirely still works,
 * so partial updates of `name`/`description` are unaffected.
 */
class UpdateDebateFormatRequest extends FormRequest
{
    use ValidatesPhaseConfig;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],

            'phase_config'                              => ['sometimes', 'array', $this->phaseConfigShapeRule()],
            'phase_config.speech_time_seconds'          => ['required_with:phase_config', 'integer', 'min:60', 'max:1800'],
            'phase_config.has_reply_speech'             => ['required_with:phase_config', 'boolean'],
            'phase_config.reply_time_seconds'           => $this->replyTimeRules(),
            'phase_config.motion_reveal_offset_hours'   => ['required_with:phase_config', 'numeric', 'min:0', 'max:168'],
            'phase_config.prep_rooms_open_offset_hours' => ['required_with:phase_config', 'numeric', 'min:0', 'max:24'],
        ];
    }
}
