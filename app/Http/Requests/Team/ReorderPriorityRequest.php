<?php

namespace App\Http\Requests\Team;

use App\Models\TeamMember;
use Illuminate\Foundation\Http\FormRequest;

class ReorderPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'members'            => ['required', 'array', 'min:1'],
            'members.*.user_id'  => ['required', 'integer'],
            'members.*.priority' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $team    = $this->route('team');
            $members = $this->input('members', []);

            // All provided user_ids must be current members of this team
            $userIds = array_column($members, 'user_id');
            foreach ($userIds as $userId) {
                $exists = TeamMember::where('team_id', $team->id)
                    ->where('user_id', $userId)
                    ->where('status', 'current')
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add(
                        'members',
                        "المستخدم رقم {$userId} ليس عضواً حالياً في هذا الفريق. | User ID {$userId} is not an active member of this team."
                    );
                }
            }

            // Priorities must be unique across the provided list
            $priorities = array_column($members, 'priority');
            if (count($priorities) !== count(array_unique($priorities))) {
                $validator->errors()->add(
                    'members',
                    'قيم الأولوية يجب أن تكون فريدة. | Priority values must be unique.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'members.required'            => 'قائمة الأعضاء مطلوبة. | Members list is required.',
            'members.*.user_id.required'  => 'معرّف المستخدم مطلوب لكل عضو. | user_id is required for each member.',
            'members.*.priority.required' => 'الأولوية مطلوبة لكل عضو. | Priority is required for each member.',
            'members.*.priority.min'      => 'الأولوية يجب أن تكون 1 على الأقل. | Priority must be at least 1.',
        ];
    }
}
