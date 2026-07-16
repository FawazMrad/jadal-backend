<?php

namespace App\Exports\AdminStats;

class ComplaintAccountabilityExport extends BaseStatsExport
{
    protected function sheetName(): string
    {
        return 'Complaint Accountability';
    }

    public function headings(): array
    {
        return [
            'User ID', 'Name', 'Complained About As', 'Complaints Total',
            'Debates Involved', 'Complaints / 100 Debates',
            'Open', 'Under Review', 'Resolved', 'Dismissed',
            'Avg Hours To Last Update (approx)',
        ];
    }

    protected function buildRows(): array
    {
        $rows = array_map(fn (array $e) => [
            $e['user_id'],
            $e['name'],
            $e['target_role'],
            $e['complaints_total'],
            $e['debates_involved'],
            $e['complaints_per_100_debates'],
            $e['status_breakdown']['open'] ?? 0,
            $e['status_breakdown']['under_review'] ?? 0,
            $e['status_breakdown']['resolved'] ?? 0,
            $e['status_breakdown']['dismissed'] ?? 0,
            $e['avg_time_to_last_update_hours_approx'],
        ], $this->payload['entries']);

        // Keep the legacy/unattributed count visible inside the sheet too.
        $rows[] = [];
        $rows[] = ['', 'Unattributed complaints (no target recorded)', '', $this->payload['unattributed_total']];

        return $rows;
    }
}
