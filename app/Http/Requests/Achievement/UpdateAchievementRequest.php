<?php

namespace App\Http\Requests\Achievement;

use App\Models\Achievement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'  => ['sometimes', 'string', 'max:255'],
            'type'  => ['sometimes', Rule::in(Achievement::TYPES)],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
