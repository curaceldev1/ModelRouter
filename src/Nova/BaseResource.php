<?php

namespace Curacel\LlmOrchestrator\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Resource as NovaResource;

abstract class BaseResource extends NovaResource
{
    /**
     * Get the displayable group associated with the resource.
     */
    public static function group(): string
    {
        return __('LLM Orchestrator');
    }

    /**
     * Get the default sort for the resource.
     */
    public static function indexQuery(Request $request, $query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Get a JSON field for Nova display.
     */
    protected function getJsonField(string $label, string $attribute): \Laravel\Nova\Fields\Code
    {
        return \Laravel\Nova\Fields\Code::make($label, $attribute)
            ->language('json')
            ->rules('nullable', 'json')
            ->readonly();
    }
}
