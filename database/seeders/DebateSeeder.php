<?php

namespace Database\Seeders;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\Motion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DebateSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $formats  = DebateFormat::all();
            $motions  = Motion::all();
            $admins   = User::where('role', 'admin')->get();

            $statuses = [
                'completed', 'completed', 'completed', 'completed', 'completed',  // 5 completed
                'completed', 'completed', 'completed',                             // 8 completed total
                'live',                                                            // 1 live
                'announced', 'announced',                                          // 2 announced
                'scheduled', 'scheduled', 'scheduled',                            // 3 scheduled
                'teams-selected', 'teams-selected',                               // 2 teams-selected
                'cancelled', 'cancelled',                                          // 2 cancelled
                'scheduled',                                                       // 1 more scheduled
            ];

            $debateTitles = [
                'بطولة نادي جدل الربيعية الأولى',
                'The Spring Open Championship',
                'مناظرة السياسة التعليمية',
                'Environment & Policy Debate',
                'نقاش حقوق الشباب',
                'Technology & Society Debate',
                'بطولة المدارس الثانوية',
                'Inter-University Championship',
                'مناظرة الاقتصاد الرقمي',
                'Social Media Impact Debate',
                'نقاش مستقبل الذكاء الاصطناعي',
                'Global Citizenship Debate',
                'مناظرة قضايا المناخ',
                'Human Rights & Governance',
                'نقاش التنمية المستدامة',
                'Innovation & Entrepreneurship',
                'مناظرة التعليم العالي',
                'Healthcare Policy Debate',
                'نقاش العدالة الاجتماعية',
                'Cultural Identity Debate',
            ];

            foreach ($statuses as $i => $status) {
                $scheduledAt = match ($status) {
                    'completed'     => now()->subDays(rand(7, 90)),
                    'live'          => now()->subHours(rand(1, 2)),
                    'announced',
                    'teams-selected' => now()->addDays(rand(3, 14)),
                    'scheduled'     => now()->addDays(rand(7, 60)),
                    'cancelled'     => now()->subDays(rand(1, 30)),
                    default         => now()->addDays(7),
                };

                $startedAt = null;
                $endedAt   = null;

                if (in_array($status, ['live', 'completed'])) {
                    $startedAt = (clone $scheduledAt)->modify('+5 minutes');
                }

                if ($status === 'completed') {
                    $endedAt = (clone $startedAt)->modify('+' . rand(90, 180) . ' minutes');
                }

                Debate::create([
                    'format_id'        => $formats->random()->id,
                    'motion_id'        => $motions->random()->id,
                    'created_by'       => $admins->random()->id,
                    'title'            => $debateTitles[$i] ?? 'مناظرة رقم ' . ($i + 1),
                    'description'      => rand(0, 1) ? 'مناظرة رسمية تهدف إلى تنمية مهارات الحجة والإقناع لدى المتنافسين.' : null,
                    'status'           => $status,
                    'livekit_room_name' => 'room-' . Str::uuid(),
                    'recording_url'    => $status === 'completed' && rand(0, 1) ? 'https://recordings.jadal.sa/' . Str::random(12) : null,
                    'transcript'       => $status === 'completed' && rand(0, 1) ? 'نص المناظرة الكامل...' : null,
                    'scheduled_at'     => $scheduledAt,
                    'started_at'       => $startedAt,
                    'ended_at'         => $endedAt,
                ]);
            }

            $this->command->info('✓ Debates seeded: 20 debates with mixed statuses.');
        } catch (\Throwable $e) {
            $this->command->error('DebateSeeder failed: ' . $e->getMessage());
        }
    }
}
