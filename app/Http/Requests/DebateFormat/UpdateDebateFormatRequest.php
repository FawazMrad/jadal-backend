<?php

namespace App\Http\Requests\DebateFormat;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDebateFormatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'phase_config'                              => ['sometimes', 'array'],
            'phase_config.speech_time_seconds'          => ['sometimes', 'required', 'integer', 'min:60', 'max:1800'],
            'phase_config.has_reply_speech'             => ['sometimes', 'required', 'boolean'],
            'phase_config.reply_time_seconds'           => ['required_if:phase_config.has_reply_speech,true', 'integer', 'min:60', 'max:600'],
            'phase_config.motion_reveal_offset_hours'   => ['sometimes', 'required', 'numeric', 'min:0', 'max:168'],
            'phase_config.prep_rooms_open_offset_hours' => ['sometimes', 'required', 'numeric', 'min:0', 'max:24'],
        ];
    }
}
