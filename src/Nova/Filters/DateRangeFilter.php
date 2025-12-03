<?php

namespace Curacel\LlmOrchestrator\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\DateFilter;
use Laravel\Nova\Http\Requests\NovaRequest;

class DateRangeFilter extends DateFilter
{
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->whereDate('created_at', '>=', $value);
    }
}
