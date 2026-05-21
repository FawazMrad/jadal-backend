<?php

namespace Database\Seeders;

use App\Models\DebateFormat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DebateFormatSeeder extends Seeder
{
    public function run(): void
    {
        try {
            // Clear existing formats before re-seeding
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DebateFormat::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $formats = [
                [
                    'name'        => 'British Parliamentary',
                    'description' => 'Four teams of two speakers compete: two government teams (Opening and Closing) and two opposition teams (Opening and Closing).',
                    'phase_config' => [
                        ['name' => 'Prime Minister Opening',          'order_index' => 1,  'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'Leader of Opposition Opening',    'order_index' => 2,  'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'Deputy Prime Minister',           'order_index' => 3,  'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'Deputy Leader of Opposition',     'order_index' => 4,  'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'Member for Government',           'order_index' => 5,  'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'Member for Opposition',           'order_index' => 6,  'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'Government Whip',                 'order_index' => 7,  'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'Opposition Whip',                 'order_index' => 8,  'duration_seconds' => 420, 'role' => 'opposition'],
                    ],
                ],
                [
                    'name'        => 'Asian Parliamentary',
                    'description' => 'Two teams of three speakers compete on a given motion.',
                    'phase_config' => [
                        ['name' => 'Prime Minister',              'order_index' => 1, 'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'Leader of Opposition',        'order_index' => 2, 'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'Deputy Prime Minister',       'order_index' => 3, 'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'Deputy Leader of Opposition', 'order_index' => 4, 'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'Government Whip',             'order_index' => 5, 'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'Opposition Whip',             'order_index' => 6, 'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'Opposition Reply',            'order_index' => 7, 'duration_seconds' => 240, 'role' => 'opposition'],
                        ['name' => 'Government Reply',            'order_index' => 8, 'duration_seconds' => 240, 'role' => 'proposition'],
                    ],
                ],
                [
                    'name'        => 'Karl Popper',
                    'description' => 'Teams of three students debate a resolution. Named after philosopher Karl Popper.',
                    'phase_config' => [
                        ['name' => 'First Affirmative Constructive',     'order_index' => 1,  'duration_seconds' => 360, 'role' => 'proposition'],
                        ['name' => 'Cross Examination by Negative',      'order_index' => 2,  'duration_seconds' => 180, 'role' => 'opposition'],
                        ['name' => 'First Negative Constructive',        'order_index' => 3,  'duration_seconds' => 360, 'role' => 'opposition'],
                        ['name' => 'Cross Examination by Affirmative',   'order_index' => 4,  'duration_seconds' => 180, 'role' => 'proposition'],
                        ['name' => 'Second Affirmative Rebuttal',        'order_index' => 5,  'duration_seconds' => 360, 'role' => 'proposition'],
                        ['name' => 'Cross Examination by Negative',      'order_index' => 6,  'duration_seconds' => 180, 'role' => 'opposition'],
                        ['name' => 'Second Negative Rebuttal',           'order_index' => 7,  'duration_seconds' => 360, 'role' => 'opposition'],
                        ['name' => 'Cross Examination by Affirmative',   'order_index' => 8,  'duration_seconds' => 180, 'role' => 'proposition'],
                        ['name' => 'Third Affirmative Rebuttal',         'order_index' => 9,  'duration_seconds' => 300, 'role' => 'proposition'],
                        ['name' => 'Third Negative Rebuttal',            'order_index' => 10, 'duration_seconds' => 300, 'role' => 'opposition'],
                    ],
                ],
                [
                    'name'        => 'Lincoln-Douglas',
                    'description' => 'A one-on-one debate format focusing on values and philosophy.',
                    'phase_config' => [
                        ['name' => 'Affirmative Constructive',          'order_index' => 1, 'duration_seconds' => 360, 'role' => 'proposition'],
                        ['name' => 'Negative Cross Examination',        'order_index' => 2, 'duration_seconds' => 180, 'role' => 'opposition'],
                        ['name' => 'Negative Constructive & Rebuttal',  'order_index' => 3, 'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'Affirmative Cross Examination',     'order_index' => 4, 'duration_seconds' => 180, 'role' => 'proposition'],
                        ['name' => 'Affirmative Rebuttal',              'order_index' => 5, 'duration_seconds' => 240, 'role' => 'proposition'],
                        ['name' => 'Negative Rebuttal',                 'order_index' => 6, 'duration_seconds' => 180, 'role' => 'opposition'],
                        ['name' => 'Affirmative Final Rebuttal',        'order_index' => 7, 'duration_seconds' => 180, 'role' => 'proposition'],
                    ],
                ],
                [
                    'name'        => 'World Schools',
                    'description' => 'International format used in the World Schools Debating Championship.',
                    'phase_config' => [
                        ['name' => 'First Proposition',  'order_index' => 1, 'duration_seconds' => 480, 'role' => 'proposition'],
                        ['name' => 'First Opposition',   'order_index' => 2, 'duration_seconds' => 480, 'role' => 'opposition'],
                        ['name' => 'Second Proposition', 'order_index' => 3, 'duration_seconds' => 480, 'role' => 'proposition'],
                        ['name' => 'Second Opposition',  'order_index' => 4, 'duration_seconds' => 480, 'role' => 'opposition'],
                        ['name' => 'Third Proposition',  'order_index' => 5, 'duration_seconds' => 480, 'role' => 'proposition'],
                        ['name' => 'Third Opposition',   'order_index' => 6, 'duration_seconds' => 480, 'role' => 'opposition'],
                        ['name' => 'Opposition Reply',   'order_index' => 7, 'duration_seconds' => 240, 'role' => 'opposition'],
                        ['name' => 'Proposition Reply',  'order_index' => 8, 'duration_seconds' => 240, 'role' => 'proposition'],
                    ],
                ],
                [
                    'name'        => 'مناظرة عربية',
                    'description' => 'نظام مناظرة عربي يعتمد على ثلاثة متحدثين لكل جانب مع جولات للرد والتفنيد.',
                    'phase_config' => [
                        ['name' => 'المقدم الأول للفريق المؤيد',     'order_index' => 1, 'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'المقدم الأول للفريق المعارض',    'order_index' => 2, 'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'المقدم الثاني للفريق المؤيد',    'order_index' => 3, 'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'المقدم الثاني للفريق المعارض',   'order_index' => 4, 'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'المقدم الثالث للفريق المؤيد',    'order_index' => 5, 'duration_seconds' => 420, 'role' => 'proposition'],
                        ['name' => 'المقدم الثالث للفريق المعارض',   'order_index' => 6, 'duration_seconds' => 420, 'role' => 'opposition'],
                        ['name' => 'رد الفريق المعارض',              'order_index' => 7, 'duration_seconds' => 240, 'role' => 'opposition'],
                        ['name' => 'رد الفريق المؤيد',               'order_index' => 8, 'duration_seconds' => 240, 'role' => 'proposition'],
                    ],
                ],
            ];

            foreach ($formats as $format) {
                DebateFormat::create($format);
            }

            $this->command->info('✓ Debate formats seeded: ' . count($formats) . ' formats.');
        } catch (\Throwable $e) {
            $this->command->error('DebateFormatSeeder failed: ' . $e->getMessage());
        }
    }
}
