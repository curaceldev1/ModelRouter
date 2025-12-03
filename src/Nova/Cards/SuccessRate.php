<?php

namespace Curacel\LlmOrchestrator\Nova\Cards;

use Curacel\LlmOrchestrator\Models\ExecutionLog;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;

class SuccessRate extends Value
{
    /**
     * Calculate the value of the metric.
     */
    public function calculate(NovaRequest $request): mixed
    {
        $totalRequests = ExecutionLog::count();
        $successfulRequests = ExecutionLog::where('is_successful', true)->count();

        if ($totalRequests === 0) {
            return $this->result(0)->suffix('%');
        }

        $successRate = round(($successfulRequests / $totalRequests) * 100, 1);

        return $this->result($successRate)->suffix('%');
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
        return 'success-rate';
    }

    /**
     * Get the displayable name of the metric.
     */
    public function name(): string
    {
        return 'LLM Success Rate';
    }
}
