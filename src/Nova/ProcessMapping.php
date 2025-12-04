<?php

namespace Curacel\LlmOrchestrator\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;

class ProcessMapping extends BaseResource
{
    /**
     * The model the resource corresponds to.
     */
    public static string $model = \Curacel\LlmOrchestrator\Models\ProcessMapping::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     */
    public static $title = 'process_name';

    /**
     * The columns that should be searched.
     */
    public static $search = [
        'id', 'process_name', 'client', 'model', 'description',
    ];

    /**
     * Get the displayable label of the resource.
     */
    public static function label(): string
    {
        return __('LLM Process Mappings');
    }

    /**
     * Get the displayable singular label of the resource.
     */
    public static function singularLabel(): string
    {
        return __('Process Mapping');
    }

    /**
     * Get the fields displayed by the resource.
     */
    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Process Name')
                ->sortable()
                ->rules('required', 'max:255')
                ->help('A unique name identifying the LLM process. e.g., "claim_summary_generation"'),

            Select::make('Client')
                ->options($this->getClientOptions())
                ->displayUsingLabels()
                ->sortable()
                ->rules('required'),

            Text::make('Model')
                ->sortable()
                ->rules('required')
                ->help('e.g., gpt-4o-mini, claude-3-5-sonnet-20241022, gemini-1.5-pro'),

            Boolean::make('Is Active')
                ->sortable(),

            Textarea::make('Description')
                ->hideFromIndex()
                ->nullable()
                ->alwaysShow(),

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
            new Filters\ModelFilter,
            new Filters\ActiveStatusFilter,
        ];
    }

    /**
     * Get the actions available for the resource.
     */
    public function actions(Request $request): array
    {
        return [
            new Actions\ToggleActiveStatus,
        ];
    }

    /**
     * Get the URI key for the resource.
     */
    public static function uriKey(): string
    {
        return 'llm-process-mappings';
    }

    /**
     * Get available client options from configuration.
     */
    protected function getClientOptions(): array
    {
        $clients = config('llm-orchestrator.clients', []);
        $options = [];

        foreach (array_keys($clients) as $client) {
            $options[$client] = ucfirst($client);
        }

        return $options ?: [
            'openai' => 'OpenAI',
            'claude' => 'Claude',
            'gemini' => 'Gemini',
        ];
    }
}
