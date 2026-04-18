<?php

namespace App\Http\Requests\Team;

use App\Models\TeamMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'string', 'max:100'],
            // New leader must be an existing debater; current-member check is in withValidator
            'leader_id' => ['sometimes', 'integer', Rule::exists('users', 'id')->where('role', 'debater')],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->has('leader_id')) {
                return;
            }

            $team = $this->route('team');

            $isMember = TeamMember::where('team_id', $team->id)
                ->where('user_id', $this->leader_id)
                ->where('status', 'current')
                ->exists();

            if (! $isMember) {
                $validator->errors()->add(
                    'leader_id',
                    'القائد الجديد يجب أن يكون عضواً حالياً في الفريق. | New leader must be an active member of this team.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'leader_id.exists' => 'القائد المختار غير موجود أو ليس متناظراً. | Selected leader is not a valid debater.',
        ];
    }
}
