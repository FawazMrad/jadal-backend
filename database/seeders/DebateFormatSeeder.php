<?php

namespace Database\Seeders;

use App\Models\DebateFormat;
use Illuminate\Database\Seeder;

class DebateFormatSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $formats = [
                [
                    'name'        => 'British Parliamentary',
                    'description' => 'Four teams of two speakers compete: two government teams (Opening and Closing) and two opposition teams (Opening and Closing).',
                    'phase_config' => [
                        'phases' => [
                            ['name' => 'Prime Minister Opening', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'Leader of Opposition Opening', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'Deputy Prime Minister', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'Deputy Leader of Opposition', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'Member for Government', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'Member for Opposition', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'Government Whip', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'Opposition Whip', 'duration' => 420, 'side' => 'opposition'],
                        ],
                        'speakers_per_team' => 2,
                        'teams_count' => 4,
                    ],
                ],
                [
                    'name'        => 'Asian Parliamentary',
                    'description' => 'Two teams of three speakers compete on a given motion.',
                    'phase_config' => [
                        'phases' => [
                            ['name' => 'Prime Minister', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'Leader of Opposition', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'Deputy Prime Minister', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'Deputy Leader of Opposition', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'Government Whip', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'Opposition Whip', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'Opposition Reply', 'duration' => 240, 'side' => 'opposition'],
                            ['name' => 'Government Reply', 'duration' => 240, 'side' => 'proposition'],
                        ],
                        'speakers_per_team' => 3,
                        'teams_count' => 2,
                    ],
                ],
                [
                    'name'        => 'Karl Popper',
                    'description' => 'Teams of three students debate a resolution. Named after philosopher Karl Popper.',
                    'phase_config' => [
                        'phases' => [
                            ['name' => 'First Affirmative Constructive', 'duration' => 360, 'side' => 'proposition'],
                            ['name' => 'Cross Examination by Negative', 'duration' => 180, 'side' => 'opposition'],
                            ['name' => 'First Negative Constructive', 'duration' => 360, 'side' => 'opposition'],
                            ['name' => 'Cross Examination by Affirmative', 'duration' => 180, 'side' => 'proposition'],
                            ['name' => 'Second Affirmative Rebuttal', 'duration' => 360, 'side' => 'proposition'],
                            ['name' => 'Cross Examination by Negative', 'duration' => 180, 'side' => 'opposition'],
                            ['name' => 'Second Negative Rebuttal', 'duration' => 360, 'side' => 'opposition'],
                            ['name' => 'Cross Examination by Affirmative', 'duration' => 180, 'side' => 'proposition'],
                            ['name' => 'Third Affirmative Rebuttal', 'duration' => 300, 'side' => 'proposition'],
                            ['name' => 'Third Negative Rebuttal', 'duration' => 300, 'side' => 'opposition'],
                        ],
                        'speakers_per_team' => 3,
                        'teams_count' => 2,
                    ],
                ],
                [
                    'name'        => 'Lincoln-Douglas',
                    'description' => 'A one-on-one debate format focusing on values and philosophy.',
                    'phase_config' => [
                        'phases' => [
                            ['name' => 'Affirmative Constructive', 'duration' => 360, 'side' => 'proposition'],
                            ['name' => 'Negative Cross Examination', 'duration' => 180, 'side' => 'opposition'],
                            ['name' => 'Negative Constructive & Rebuttal', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'Affirmative Cross Examination', 'duration' => 180, 'side' => 'proposition'],
                            ['name' => 'Affirmative Rebuttal', 'duration' => 240, 'side' => 'proposition'],
                            ['name' => 'Negative Rebuttal', 'duration' => 180, 'side' => 'opposition'],
                            ['name' => 'Affirmative Final Rebuttal', 'duration' => 180, 'side' => 'proposition'],
                        ],
                        'speakers_per_team' => 1,
                        'teams_count' => 2,
                    ],
                ],
                [
                    'name'        => 'World Schools',
                    'description' => 'International format used in the World Schools Debating Championship.',
                    'phase_config' => [
                        'phases' => [
                            ['name' => 'First Proposition', 'duration' => 480, 'side' => 'proposition'],
                            ['name' => 'First Opposition', 'duration' => 480, 'side' => 'opposition'],
                            ['name' => 'Second Proposition', 'duration' => 480, 'side' => 'proposition'],
                            ['name' => 'Second Opposition', 'duration' => 480, 'side' => 'opposition'],
                            ['name' => 'Third Proposition', 'duration' => 480, 'side' => 'proposition'],
                            ['name' => 'Third Opposition', 'duration' => 480, 'side' => 'opposition'],
                            ['name' => 'Opposition Reply', 'duration' => 240, 'side' => 'opposition'],
                            ['name' => 'Proposition Reply', 'duration' => 240, 'side' => 'proposition'],
                        ],
                        'speakers_per_team' => 3,
                        'teams_count' => 2,
                    ],
                ],
                [
                    'name'        => 'مناظرة عربية',
                    'description' => 'نظام مناظرة عربي يعتمد على ثلاثة متحدثين لكل جانب مع جولات للرد والتفنيد.',
                    'phase_config' => [
                        'phases' => [
                            ['name' => 'المقدم الأول للفريق المؤيد', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'المقدم الأول للفريق المعارض', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'المقدم الثاني للفريق المؤيد', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'المقدم الثاني للفريق المعارض', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'المقدم الثالث للفريق المؤيد', 'duration' => 420, 'side' => 'proposition'],
                            ['name' => 'المقدم الثالث للفريق المعارض', 'duration' => 420, 'side' => 'opposition'],
                            ['name' => 'رد الفريق المعارض', 'duration' => 240, 'side' => 'opposition'],
                            ['name' => 'رد الفريق المؤيد', 'duration' => 240, 'side' => 'proposition'],
                        ],
                        'speakers_per_team' => 3,
                        'teams_count' => 2,
                    ],
                ],
            ];

            foreach ($formats as $format) {
                DebateFormat::firstOrCreate(['name' => $format['name']], $format);
            }

            $this->command->info('✓ Debate formats seeded: ' . count($formats) . ' formats.');
        } catch (\Throwable $e) {
            $this->command->error('DebateFormatSeeder failed: ' . $e->getMessage());
        }
    }
}
