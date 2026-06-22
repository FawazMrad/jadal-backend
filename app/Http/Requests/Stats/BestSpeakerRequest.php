<?php

namespace App\Http\Requests\Stats;

use App\Services\Stats\PositionCodes;

class BestSpeakerRequest extends StatsFilterRequest
{
    public function withValidator($validator): void
    {
        parent::withValidator($validator);

        $validator->after(function ($v) {
            if (PositionCodes::containsReply($this->csv('positions'))) {
                $v->errors()->add(
                    'positions',
                    'Reply positions cannot be used to filter best-speaker stats — best speaker is determined from main speeches only.'
                );
            }
        });
    }
}
