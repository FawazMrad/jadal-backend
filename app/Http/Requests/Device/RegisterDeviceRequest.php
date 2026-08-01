<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:500'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            // Anything other than 'ar' falls back to English copy at send time.
            'locale'   => ['sometimes', Rule::in(['ar', 'en'])],
        ];
    }
}
