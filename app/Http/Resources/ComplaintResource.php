<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'filed_by'       => new UserResource($this->whenLoaded('filedBy')),
            'debate_id'      => $this->debate_id,
            'target_user_id' => $this->target_user_id,
            'target_role'    => $this->target_role,
            'target_user'    => new UserResource($this->whenLoaded('targetUser')),
            'description'    => $this->description,
            'status'         => $this->status,
            'admin_response' => $this->admin_response,
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
        ];
    }
}
