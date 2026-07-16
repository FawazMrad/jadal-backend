<?php

namespace App\Exports\AdminStats;

use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FrameworkFairnessExport extends BaseStatsExport
{
    protected function sheetName(): string
    {
        return 'Framework Fairness';
    }

    public function headings(): array
    {
        return ['Framework ID', 'Framework', 'Prop Win Rate', 'Opp Win Rate', 'Imbalance Score', 'Flagged', 'Debates'];
    }

    protected function buildRows(): array
    {
        return array_map(fn (array $fw) => [
            $fw['framework_id'],
            $fw['label'],
            $fw['prop_win_rate'],
            $fw['opp_win_rate'],
            $fw['imbalance_score'],
            $fw['flagged'] ? 'YES' : 'no',
            $fw['n_debates'],
        ], $this->payload['frameworks']);
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_PERCENTAGE_00,
            'D' => NumberFormat::FORMAT_PERCENTAGE_00,
            'E' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    protected function highlightRow(array $row): bool
    {
        return ($row[5] ?? null) === 'YES';
    }
}
