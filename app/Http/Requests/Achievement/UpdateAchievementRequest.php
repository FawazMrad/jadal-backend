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

    /**
     * `image` has three meanings, keyed off presence/value rather than a
     * separate flag:
     *   - a real uploaded file       -> replace it (validated as an image below)
     *   - present but empty ("")     -> remove it (the global
     *                                    ConvertEmptyStringsToNull middleware
     *                                    turns "" into null before this runs,
     *                                    so `nullable` is what actually lets
     *                                    the "remove" case through)
     *   - key omitted entirely       -> leave the current image untouched
     */
    public function rules(): array
    {
        return [
            'name'  => ['sometimes', 'string', 'max:255'],
            'type'  => ['sometimes', Rule::in(Achievement::TYPES)],
            'image' => $this->hasFile('image')
                ? ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048']
                : ['sometimes', 'nullable', function ($attribute, $value, $fail) {
                    $fail('يجب إرسال ملف صورة فعلي، أو تركه فارغاً لإزالة الصورة. | Send an actual image file, or leave it empty to remove the image.');
                }],
        ];
    }
}
