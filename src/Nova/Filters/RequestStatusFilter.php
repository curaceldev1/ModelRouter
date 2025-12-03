<?php

namespace Curacel\LlmOrchestrator\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class RequestStatusFilter extends Filter
{
    public $component = 'select-filter';

    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->where('is_successful', $value);
    }

    public function options(NovaRequest $request): array
    {
        return [
            'Successful' => 1,
            'Failed' => 0,
        ];
    }
}
