<?php

namespace Curacel\LlmOrchestrator\Nova\Cards;

use Curacel\LlmOrchestrator\Models\Metric;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;

class TotalCost extends Value
{
    /**
     * Calculate the value of the metric.
     */
    public function calculate(NovaRequest $request): mixed
    {
        $totalCost = Metric::sum('total_cost');

        return $this->result($totalCost)->format('$0,0.00');
    }

    /**
     * Get the ranges available for the metric.
     */
    public function ranges(): array
    {
        return [
            30 => 'Last 30 Days',
            60 => 'Last 60 Days',
            90 => 'Last 90 Days',
        ];
    }

    /**
     * Get the URI key for the metric.
     */
    public function uriKey(): string
    {
        return 'total-cost';
    }

    /**
     * Get the displayable name of the metric.
     */
    public function name(): string
    {
        return 'Total LLM Cost';
    }
}
