<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => ['sometimes', 'string', 'max:100'],
            'phone'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today', 'after:1900-01-01'],
            'location'   => ['sometimes', 'nullable', 'string', 'max:150'],
            // Retired (frontend spec §6.4 — statistics are public for everyone).
            // Still ACCEPTED so an un-updated client that keeps sending it does
            // not start getting 422s mid-rollout, but it is not in validated()
            // output and is never persisted — a pure no-op. Remove this rule
            // once the new app version is fully deployed.
            'stats_visible' => ['sometimes'],
        ];
    }

    /**
     * Drop the retired field so it can never reach ->update(). Belt and braces:
     * it is no longer in User::$fillable either.
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();
        unset($data['stats_visible']);

        return $key === null ? $data : data_get($data, $key, $default);
    }

    public function messages(): array
    {
        return [
            'name.max'  => 'الاسم يجب ألا يتجاوز 100 حرف. | Name must not exceed 100 characters.',
            'phone.max' => 'رقم الهاتف يجب ألا يتجاوز 20 حرفاً. | Phone must not exceed 20 characters.',
        ];
    }
}
