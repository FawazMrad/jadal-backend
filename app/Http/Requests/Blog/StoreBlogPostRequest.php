<?php

namespace App\Http\Requests\Blog;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'           => ['required', 'string', 'max:255'],
            'content'         => ['required', 'string'],
            'cover_image'     => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'category_ids'    => ['sometimes', 'array'],
            'category_ids.*'  => ['integer', 'exists:blog_categories,id'],
            'tag_ids'         => ['sometimes', 'array'],
            'tag_ids.*'       => ['integer', 'exists:blog_tags,id'],
        ];
    }
}
