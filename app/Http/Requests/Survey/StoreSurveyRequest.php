<?php

namespace App\Http\Requests\Survey;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            'description'    => ['sometimes', 'nullable', 'string'],
            'closes_at'      => ['sometimes', 'nullable', 'date', 'after:now'],
            'target_roles'   => ['sometimes', 'array'],
            'target_roles.*' => ['string', Rule::in(['debater', 'trainer', 'judge', 'admin'])],
            'team_ids'       => ['sometimes', 'array'],
            'team_ids.*'     => ['integer', 'exists:teams,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $user = $this->user();

            if ($user->role === 'admin') {
                if (empty($this->input('target_roles'))) {
                    $v->errors()->add('target_roles', 'The target_roles field is required for admin surveys.');
                }
            }

            if ($user->role === 'trainer') {
                $teamIds = $this->input('team_ids', []);

                if (empty($teamIds)) {
                    $v->errors()->add('team_ids', 'The team_ids field is required for trainer surveys.');
                    return;
                }

                $ownedIds = Team::where('created_by', $user->id)
                    ->whereIn('id', $teamIds)
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->toArray();

                foreach ($teamIds as $teamId) {
                    if (! in_array((int) $teamId, $ownedIds)) {
                        $v->errors()->add('team_ids', "Team ID {$teamId} does not belong to you.");
                        break;
                    }
                }
            }
        });
    }
}
