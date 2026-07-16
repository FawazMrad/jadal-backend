<?php

namespace App\Http\Requests\AdminStats;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared base for every admin-stats endpoint (JSON + Excel export alike):
 * admin-only authorization plus the universal from/to/formats filter rules.
 * Non-admins get 403 from authorize() before any query runs (the routes are
 * additionally inside the role:admin group — belt and suspenders).
 */
abstract class BaseAdminStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** Universal filter rules shared by the stats that take a time window. */
    protected function universalRules(): array
    {
        return [
            'from'    => ['sometimes', 'nullable', 'date_format:Y-m'],
            'to'      => ['sometimes', 'nullable', 'date_format:Y-m', 'after_or_equal:from'],
            'formats' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
        ];
    }
}
