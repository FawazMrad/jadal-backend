<?php

namespace App\Http\Requests\Debate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDebatesRequest extends FormRequest
{
    public const ALLOWED_STATUSES = [
        'scheduled', 'announced', 'teams-selected', 'live', 'completed', 'cancelled',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Split the comma-separated `status` query param into a `statuses` array so
     * each value can be validated against the allowed enum.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('status')) {
            $statuses = array_values(array_filter(
                array_map('trim', explode(',', (string) $this->input('status')))
            ));

            if (! empty($statuses)) {
                $this->merge(['statuses' => $statuses]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'status'     => ['nullable', 'string'],
            'statuses'   => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in(self::ALLOWED_STATUSES)],
            'format_id'  => ['nullable', 'integer', 'exists:debate_formats,id'],
            'motion_id'  => ['nullable', 'integer', 'exists:motions,id'],
            'from_date'  => ['nullable', 'date'],
            'to_date'    => ['nullable', 'date', 'after_or_equal:from_date'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:50'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'sort'       => ['nullable', Rule::in(['scheduled_asc', 'scheduled_desc', 'created_desc'])],
        ];
    }
}
