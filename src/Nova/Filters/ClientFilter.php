<?php

namespace Curacel\LlmOrchestrator\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class ClientFilter extends Filter
{
    public $component = 'select-filter';

    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->where('client', $value);
    }

    public function options(NovaRequest $request): array
    {
        // Get available clients from configuration
        $clients = config('llm-orchestrator.clients', []);

        $options = [];
        foreach (array_keys($clients) as $client) {
            $options[ucfirst($client)] = $client;
        }

        return $options;
    }
}
