<?php

namespace App\Http\Requests\Stats;

use App\Services\Stats\PositionCodes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from'       => ['nullable', 'date_format:Y-m'],
            'to'         => ['nullable', 'date_format:Y-m'],
            'group_by'   => ['nullable', Rule::in(['none', 'year', 'month'])],
            'frameworks' => ['nullable', 'string'],
            'positions'  => ['nullable', 'string'],
            'teams'      => ['nullable', 'string'],
            'series'     => ['nullable', Rule::in(['frameworks', 'positions', 'teams', 'none'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // Spec §1.4/§9 — position and framework are mutually exclusive
            // dimensions. Combined they produce a slice too granular to mean
            // anything (e.g. "3rd opposition on economic motions" over two
            // debates). The UI clears one when the other is picked; this is the
            // server-side enforcement so the rule holds for any client.
            if ($this->filled('positions') && $this->filled('frameworks')) {
                $v->errors()->add('positions', 'positions and frameworks are mutually exclusive');
            }

            // from <= to
            if ($this->filled('from') && $this->filled('to') && $this->input('to') < $this->input('from')) {
                $v->errors()->add('to', 'The `to` month must be on or after `from`.');
            }

            // positions: every code must be valid
            foreach ($this->csv('positions') as $code) {
                if (! in_array($code, PositionCodes::allValidCodes(), true)) {
                    $v->errors()->add('positions', "Invalid position code: {$code}.");
                }
            }

            // frameworks: integers only
            foreach ($this->csv('frameworks') as $fid) {
                if (! ctype_digit($fid)) {
                    $v->errors()->add('frameworks', "Invalid framework id: {$fid}.");
                }
            }

            // teams: integer ids or the literal RANDOM
            foreach ($this->csv('teams') as $tid) {
                if ($tid !== 'RANDOM' && ! ctype_digit($tid)) {
                    $v->errors()->add('teams', "Invalid team id: {$tid}.");
                }
            }
        });
    }

    /** @return string[] */
    protected function csv(string $key): array
    {
        $raw = $this->input($key);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
    }
}
