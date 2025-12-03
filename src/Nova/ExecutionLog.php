<?php

namespace Curacel\LlmOrchestrator\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;

class ExecutionLog extends BaseResource
{
    /**
     * The model the resource corresponds to.
     */
    public static string $model = \Curacel\LlmOrchestrator\Models\ExecutionLog::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     */
    public static $search = [
        'id', 'client', 'driver', 'model', 'finish_reason', 'failed_reason',
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
        return __('LLM Execution Logs');
    }

    /**
     * Get the displayable singular label of the resource.
     */
    public static function singularLabel(): string
    {
        return __('Execution Log');
    }

    /**
     * Get the fields displayed by the resource.
     */
    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),

            Badge::make('Status', 'is_successful')
                ->map([
                    1 => 'success',
                    0 => 'danger',
                ])
                ->labels([
                    'success' => 'Successful',
                    'danger' => 'Failed',
                ])
                ->sortable(),

            Text::make('Client')
                ->sortable(),

            Text::make('Driver')
                ->sortable(),

            Text::make('Model')
                ->sortable(),

            Number::make('Input Tokens')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Number::make('Output Tokens')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Number::make('Total Tokens')
                ->sortable()
                ->displayUsing(fn ($value) => number_format($value)),

            Currency::make('Cost')
                ->currency('USD')
                ->sortable()
                ->nullable(),

            Text::make('Finish Reason')
                ->nullable()
                ->hideFromIndex(),

            Text::make('Failed Reason')
                ->nullable()
                ->hideFromIndex()
                ->readonly(),

            $this->getJsonField('Request Data', 'request_data')
                ->hideFromIndex()
                ->nullable(),

            $this->getJsonField('Response Data', 'response_data')
                ->hideFromIndex()
                ->nullable(),

            DateTime::make('Created At')
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
            new Filters\RequestStatusFilter,
            new Filters\DateRangeFilter,
        ];
    }

    /**
     * Get the URI key for the resource.
     */
    public static function uriKey(): string
    {
        return 'llm-execution-logs';
    }
}
