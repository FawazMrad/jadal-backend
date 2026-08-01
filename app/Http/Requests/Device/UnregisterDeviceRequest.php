<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class UnregisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Deletion is by token, not id — the app only ever knows its own token.
        return [
            'token' => ['required', 'string', 'max:500'],
        ];
    }
}
