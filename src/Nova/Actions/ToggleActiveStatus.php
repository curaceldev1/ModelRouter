<?php

namespace Curacel\LlmOrchestrator\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class ToggleActiveStatus extends Action
{
    use Queueable;

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        foreach ($models as $model) {
            $model->update(['is_active' => ! $model->is_active]);
        }

        return Action::message('Status updated for '.$models->count().' process mappings!');
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
