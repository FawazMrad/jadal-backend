<?php

namespace App\Exports\AdminStats;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PlatformHealthExport extends BaseStatsExport
{
    protected function sheetName(): string
    {
        return 'Platform Health';
    }

    public function headings(): array
    {
        return [
            'Bucket', 'New Debaters', 'New Trainers', 'New Judges', 'New Admins',
            'Debates Created', 'Debates Completed', 'Debates Cancelled',
            'Cancellation Breakdown', 'Completion Rate', 'Avg Debates / Active Debater',
        ];
    }

    protected function buildRows(): array
    {
        $rows = [];

        foreach ($this->payload['buckets'] as $b) {
            $breakdown = [];
            foreach ($b['cancellation_breakdown'] as $reason => $n) {
                $breakdown[] = "{$reason}: {$n}";
            }

            $rows[] = [
                $b['label'],
                $b['new_users']['debater'] ?? 0,
                $b['new_users']['trainer'] ?? 0,
                $b['new_users']['judge'] ?? 0,
                $b['new_users']['admin'] ?? 0,
                $b['debates_created'],
                $b['debates_completed'],
                $b['debates_cancelled'],
                implode('; ', $breakdown),
                $b['completion_rate'],
                $b['avg_debates_per_active_debater'],
            ];

            // series=debate_format → indented per-format sub-rows under the bucket.
            foreach ($b['by_format'] ?? [] as $fmt) {
                $rows[] = [
                    "    {$b['label']} · {$fmt['format_name']}",
                    null, null, null, null,
                    $fmt['debates_created'],
                    $fmt['debates_completed'],
                    $fmt['debates_cancelled'],
                    null, null, null,
                ];
            }
        }

        return $rows;
    }

    public function columnFormats(): array
    {
        return ['J' => NumberFormat::FORMAT_PERCENTAGE_00];
    }
}
