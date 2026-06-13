<?php

namespace Tests\Feature;

use App\Models\DebateFormat;
use Tests\TestCase;

class ReplyOrderTest extends TestCase
{
    private function replyFormat(): DebateFormat
    {
        return new DebateFormat([
            'phase_config' => [
                'speech_time_seconds'         => 420,
                'has_reply_speech'             => true,
                'reply_time_seconds'           => 240,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);
    }

    public function test_reply_order_is_opposition_then_proposition(): void
    {
        $stages = $this->replyFormat()->deriveStages();

        $this->assertCount(8, $stages);

        // Stage 7 (index 6) is Opposition Reply — comes FIRST.
        $this->assertTrue($stages[6]['is_reply']);
        $this->assertEquals('opposition', $stages[6]['role']);
        $this->assertEquals('Opposition Reply', $stages[6]['name']);

        // Stage 8 (index 7) is Proposition Reply — comes SECOND.
        $this->assertTrue($stages[7]['is_reply']);
        $this->assertEquals('proposition', $stages[7]['role']);
        $this->assertEquals('Proposition Reply', $stages[7]['name']);
    }

    public function test_main_speeches_are_unaffected_by_reply_swap(): void
    {
        $stages = $this->replyFormat()->deriveStages();

        $this->assertEquals('proposition', $stages[0]['role']);
        $this->assertEquals('opposition',  $stages[1]['role']);
        $this->assertEquals('opposition',  $stages[5]['role']); // stage 6 still opp
        $this->assertFalse($stages[5]['is_reply']);
    }
}
