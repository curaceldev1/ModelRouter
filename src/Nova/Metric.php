<?php

namespace Curacel\LlmOrchestrator\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;

class Metric extends BaseResource
{
    /**
     * The model the resource corresponds to.
     */
    public static $model = \Curacel\LlmOrchestrator\Models\Metric::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     */
    public static $search = [
        'id', 'client', 'driver', 'model', 'date',
    ];

    /**
     * Determine if this resource should be available for creation for the given request.
     */
    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    /**
     * Determine if this resource should be available for updating for the given request.
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    /**
     * Get the displayable label of the resource.
     */
    public static function label(): string
    {
        return __('LLM Metrics');
    }

    /**
     * Get the displayable singular label of the resource.
     */
    public static function singularLabel(): string
    {
        return __('Metric');
    }

    /**
     * Get the fields displayed by the resource.
     */
    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),

            Date::make('Date')
                ->sortable(),

            Text::make('Client')
                ->sortable(),

            Text::make('Driver')
                ->sortable(),

            Text::make('Model')
                ->sortable(),

            Number::make('Successful Requests')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Number::make('Failed Requests')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Number::make('Total Requests')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Number::make('Input Tokens')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Number::make('Output Tokens')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Number::make('Total Tokens')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Currency::make('Total Cost')
                ->currency('USD')
                ->sortable(),

            DateTime::make('Created At')
                ->hideFromIndex()
                ->sortable()
                ->readonly(),

            DateTime::make('Updated At')
                ->hideFromIndex()
                ->sortable()
                ->readonly(),
        ];
    }

    /**
     * Get the filters available for the resource.
     */
    public function filters(Request $request): array
    {
        return [
            new Filters\ClientFilter,
            new Filters\DriverFilter,
            new Filters\ModelFilter,
            new Filters\DateRangeFilter,
        ];
    }

    /**
     * Get the URI key for the resource.
     */
    public static function uriKey(): string
    {
        return 'llm-metrics';
    }
}
