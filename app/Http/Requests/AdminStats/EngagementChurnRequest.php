<?php

namespace App\Http\Requests\AdminStats;

use Illuminate\Validation\Rule;

class EngagementChurnRequest extends BaseAdminStatsRequest
{
    public function rules(): array
    {
        // This stat runs on day-based rolling windows anchored to "now", so
        // the universal from/to/group_by filters do not apply here.
        return [
            'recent_window_days'   => ['sometimes', 'integer', 'min:1', 'max:365'],
            'baseline_window_days' => ['sometimes', 'integer', 'min:1', 'max:730'],
            'churn_threshold_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'risk_filter'          => ['sometimes', Rule::in(['churn_risk', 'ramping_up', 'all'])],
            'page'                 => ['sometimes', 'integer', 'min:1'],
            'per_page'             => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array{recent_window_days:int, baseline_window_days:int, churn_threshold_days:int, risk_filter:string, page:int, per_page:int} */
    public function params(): array
    {
        return [
            'recent_window_days'   => (int) $this->validated('recent_window_days', 30),
            'baseline_window_days' => (int) $this->validated('baseline_window_days', 90),
            'churn_threshold_days' => (int) $this->validated('churn_threshold_days', 30),
            'risk_filter'          => (string) $this->validated('risk_filter', 'all'),
            'page'                 => (int) $this->validated('page', 1),
            'per_page'             => (int) $this->validated('per_page', 25),
        ];
    }
}
