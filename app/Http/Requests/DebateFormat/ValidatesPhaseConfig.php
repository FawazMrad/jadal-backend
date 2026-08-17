<?php

namespace App\Http\Requests\DebateFormat;

use App\Models\DebateFormat;

/**
 * Shared `phase_config` validation for the create and update requests.
 *
 * These two rule sets MUST agree. They previously drifted — update used
 * `sometimes` on every inner key while create used `required` — and that drift
 * was the whole bug: an update could skip every timing rule and overwrite the
 * column with the legacy array-of-phases shape, after which
 * `DebateFormat::deriveStages()` could no longer read a speech duration and
 * debates on that format silently failed to start.
 *
 * Keeping the shape rule and the reply-time rule in one place is the point.
 */
trait ValidatesPhaseConfig
{
    /**
     * `protected_time_seconds` is newer than the other four timing keys, so a
     * client that has not been updated yet simply omits it. Rather than 422-ing
     * those callers, fill the platform default before validation runs — the
     * column then always carries a real value and the app never has to fall
     * back to its own hard-coded 60s.
     *
     * Two things this must NOT do:
     *   - overwrite an explicit 0, which legitimately means "no protected
     *     window", hence array_key_exists() rather than empty();
     *   - touch a LIST payload, because adding a string key would turn it into
     *     an associative array and slip it past phaseConfigShapeRule().
     */
    protected function prepareForValidation(): void
    {
        $config = $this->input('phase_config');

        if (! is_array($config) || array_is_list($config)) {
            return;
        }

        if (! array_key_exists('protected_time_seconds', $config)) {
            $config['protected_time_seconds'] = DebateFormat::DEFAULT_PROTECTED_TIME_SECONDS;
            $this->merge(['phase_config' => $config]);
        }
    }

    /**
     * Rejects the legacy array-of-phases shape with a message that says what is
     * actually wrong, rather than letting it surface as five confusing
     * "field is required" errors.
     */
    protected function phaseConfigShapeRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            // array_is_list() is true for [] and for [0 => …, 1 => …] — i.e. the
            // legacy list. A timing object is associative, so never a list.
            if (is_array($value) && array_is_list($value)) {
                $fail(
                    'يجب أن يكون phase_config كائن توقيتات وليس قائمة مراحل. | '
                    . 'phase_config must be an object of timing keys '
                    . '(speech_time_seconds, has_reply_speech, motion_reveal_offset_hours, '
                    . 'prep_rooms_open_offset_hours), not a list of phases.'
                );
            }
        };
    }

    /**
     * `min:60` applies only when replies are actually enabled.
     *
     * The original rules combined `required_if:…has_reply_speech,true` with an
     * unconditional `min:60`. Those bounds run whenever the key is PRESENT, not
     * only when it is required — so a no-reply format sending
     * `reply_time_seconds: 0` (exactly what DebateFormatSeeder writes for
     * "Quick Debate") was rejected with "must be at least 60", making a
     * no-reply format impossible to create or edit through the API. The seeder
     * never hit it because it writes through the model and skips validation.
     */
    protected function replyTimeRules(): array
    {
        $hasReply = filter_var(
            $this->input('phase_config.has_reply_speech'),
            FILTER_VALIDATE_BOOL
        );

        return $hasReply
            ? ['required', 'integer', 'min:60', 'max:600']
            : ['nullable', 'integer', 'min:0', 'max:600'];
    }
}
