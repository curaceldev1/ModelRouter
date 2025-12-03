<?php

namespace Curacel\LlmOrchestrator\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class DriverFilter extends Filter
{
    public $component = 'select-filter';

    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->where('driver', $value);
    }

    public function options(NovaRequest $request): array
    {
        return [
            'OpenAI' => 'openai',
            'Claude' => 'claude',
            'Gemini' => 'gemini',
            'Custom' => 'custom',
        ];
    }
}
