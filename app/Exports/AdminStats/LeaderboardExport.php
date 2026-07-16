<?php

namespace App\Exports\AdminStats;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class LeaderboardExport extends BaseStatsExport
{
    protected function sheetName(): string
    {
        // Which board is baked into the sheet itself, not just the filters line.
        return 'Leaderboard — ' . str_replace('_', ' ', (string) $this->payload['board']);
    }

    public function headings(): array
    {
        return ['Rank', 'User ID', 'Name', 'Value', 'Band', 'Debates'];
    }

    protected function buildRows(): array
    {
        return array_map(fn (array $e) => [
            $e['rank'],
            $e['user_id'],
            $e['name'],
            $e['value'],
            $e['band'] ?? '',
            $e['n_debates'],
        ], $this->payload['entries']);
    }

    public function columnFormats(): array
    {
        // win_rate values are 0..1 fractions; the improvement index and raw
        // scores are plain numbers — a two-decimal number reads fine for all.
        return [
            'D' => $this->payload['board'] === 'win_rate'
                ? NumberFormat::FORMAT_PERCENTAGE_00
                : NumberFormat::FORMAT_NUMBER_00,
        ];
    }
}
