<?php

namespace App\Exports\AdminStats;

class EngagementChurnExport extends BaseStatsExport
{
    protected function sheetName(): string
    {
        return 'Engagement & Churn';
    }

    public function headings(): array
    {
        return ['User ID', 'Name', 'Risk', 'Days Since Last Debate', 'Recent Debates', 'Baseline Debates'];
    }

    protected function buildRows(): array
    {
        return array_map(fn (array $e) => [
            $e['user_id'],
            $e['name'],
            $e['risk'],
            $e['days_since_last_debate'],
            $e['recent_n_debates'],
            $e['baseline_n_debates'],
        ], $this->payload['entries']);
    }

    protected function highlightRow(array $row): bool
    {
        return ($row[2] ?? null) === 'churn_risk';
    }
}
