<?php

namespace App\Http\Requests\Complaint;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'         => ['required', Rule::in(['open', 'under_review', 'resolved', 'dismissed'])],
            'admin_response' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
