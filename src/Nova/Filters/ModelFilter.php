<?php

namespace Curacel\LlmOrchestrator\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class ModelFilter extends Filter
{
    public $component = 'select-filter';

    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->where('model', $value);
    }

    public function options(NovaRequest $request): array
    {
        // Get distinct models from the database dynamically
        $models = $request->resource()::newModel()
            ->select('model')
            ->distinct()
            ->orderBy('model')
            ->pluck('model', 'model')
            ->toArray();

        return $models;
    }
}
