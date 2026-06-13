<?php

namespace Tests\Feature;

use App\Models\DebateFormat;
use Tests\TestCase;

class DeriveStagesTest extends TestCase
{
    public function test_produces_6_stages_without_reply_speech(): void
    {
        $format = new DebateFormat([
            'phase_config' => [
                'speech_time_seconds'         => 300,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $stages = $format->deriveStages();

        $this->assertCount(6, $stages);
    }

    public function test_produces_8_stages_with_reply_speech(): void
    {
        $format = new DebateFormat([
            'phase_config' => [
                'speech_time_seconds'         => 420,
                'has_reply_speech'             => true,
                'reply_time_seconds'           => 240,
                'motion_reveal_offset_hours'   => 0.5,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $stages = $format->deriveStages();

        $this->assertCount(8, $stages);
    }

    public function test_alternates_sides_correctly(): void
    {
        $format = new DebateFormat([
            'phase_config' => [
                'speech_time_seconds'         => 420,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $stages = $format->deriveStages();

        $this->assertEquals('proposition', $stages[0]['role']); // stage 1
        $this->assertEquals('opposition',  $stages[1]['role']); // stage 2
        $this->assertEquals('proposition', $stages[2]['role']); // stage 3
        $this->assertEquals('opposition',  $stages[3]['role']); // stage 4
        $this->assertEquals('proposition', $stages[4]['role']); // stage 5
        $this->assertEquals('opposition',  $stages[5]['role']); // stage 6
    }

    public function test_reply_stages_are_flagged_and_at_end(): void
    {
        $format = new DebateFormat([
            'phase_config' => [
                'speech_time_seconds'         => 420,
                'has_reply_speech'             => true,
                'reply_time_seconds'           => 240,
                'motion_reveal_offset_hours'   => 0.5,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $stages = $format->deriveStages();

        $this->assertFalse($stages[5]['is_reply']); // stage 6 — last regular
        $this->assertTrue($stages[6]['is_reply']);  // stage 7 — prop reply
        $this->assertTrue($stages[7]['is_reply']);  // stage 8 — opp reply
        $this->assertEquals('proposition', $stages[6]['role']);
        $this->assertEquals('opposition',  $stages[7]['role']);
    }

    public function test_order_indexes_are_sequential(): void
    {
        $format = new DebateFormat([
            'phase_config' => [
                'speech_time_seconds'         => 420,
                'has_reply_speech'             => true,
                'reply_time_seconds'           => 240,
                'motion_reveal_offset_hours'   => 0.5,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $stages = $format->deriveStages();

        foreach ($stages as $i => $stage) {
            $this->assertEquals($i + 1, $stage['order_index']);
        }
    }

    public function test_speakers_per_side_constant_is_3(): void
    {
        $this->assertEquals(3, DebateFormat::SPEAKERS_PER_SIDE);
    }
}
