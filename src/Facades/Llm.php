<?php

namespace Curacel\LlmOrchestrator\Facades;

use Curacel\LlmOrchestrator\Services\Manager;
use Illuminate\Support\Facades\Facade;

/**
 * LLM Orchestrator facade.
 *
 * Provides static access to the LLM manager for interacting with language models.
 *
 * @see Manager
 */
class Llm extends Facade
{
    /**
     * Get the facade accessor.
     */
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
