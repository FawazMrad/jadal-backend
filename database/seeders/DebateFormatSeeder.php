<?php

namespace Database\Seeders;

use App\Models\DebateFormat;
use Illuminate\Database\Seeder;

class DebateFormatSeeder extends Seeder
{
    public function run(): void
    {
        try {
            // Remove formats that don't fit the 2-team / 3-speakers-per-side invariant.
            DebateFormat::whereIn('name', ['British Parliamentary', 'Karl Popper'])->delete();

            $formats = [
                [
                    'name'        => 'Asian Parliamentary',
                    'description' => '3 speakers per side, 7-minute speeches, with reply speeches (4 min)',
                    'phase_config' => [
                        'speech_time_seconds'         => 420,
                        'has_reply_speech'             => true,
                        'reply_time_seconds'           => 240,
                        'motion_reveal_offset_hours'   => 0.5,
                        'prep_rooms_open_offset_hours' => 0.5,
                    ],
                ],
                [
                    'name'        => 'World Schools',
                    'description' => '3 speakers per side, 8-minute speeches, with reply speeches (4 min)',
                    'phase_config' => [
                        'speech_time_seconds'         => 480,
                        'has_reply_speech'             => true,
                        'reply_time_seconds'           => 240,
                        'motion_reveal_offset_hours'   => 24,
                        'prep_rooms_open_offset_hours' => 1,
                    ],
                ],
                [
                    'name'        => 'مناظرة عربية',
                    'description' => 'الصيغة العربية: 3 متحدثين لكل فريق مع خطاب رد',
                    'phase_config' => [
                        'speech_time_seconds'         => 420,
                        'has_reply_speech'             => true,
                        'reply_time_seconds'           => 240,
                        'motion_reveal_offset_hours'   => 24,
                        'prep_rooms_open_offset_hours' => 1,
                    ],
                ],
                [
                    'name'        => 'Quick Debate',
                    'description' => '3 speakers per side, 5-minute speeches, no reply',
                    'phase_config' => [
                        'speech_time_seconds'         => 300,
                        'has_reply_speech'             => false,
                        'reply_time_seconds'           => 0,
                        'motion_reveal_offset_hours'   => 1,
                        'prep_rooms_open_offset_hours' => 0.5,
                    ],
                ],
            ];

            foreach ($formats as $f) {
                DebateFormat::updateOrCreate(['name' => $f['name']], $f);
            }

            $this->command->info('✓ Debate formats seeded: ' . count($formats) . ' formats.');
        } catch (\Throwable $e) {
            $this->command->error('DebateFormatSeeder failed: ' . $e->getMessage());
        }
    }
}
