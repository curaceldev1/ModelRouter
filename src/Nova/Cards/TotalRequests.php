<?php

namespace Curacel\LlmOrchestrator\Nova\Cards;

use Curacel\LlmOrchestrator\Models\ExecutionLog;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;

class TotalRequests extends Value
{
    /**
     * Calculate the value of the metric.
     */
    public function calculate(NovaRequest $request): mixed
    {
        return $this->count($request, ExecutionLog::class);
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
        return 'total-requests';
    }

    /**
     * Get the displayable name of the metric.
     */
    public function name(): string
    {
        return 'Total LLM Requests';
    }
}
