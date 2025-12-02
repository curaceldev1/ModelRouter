<?php

namespace Curacel\LlmOrchestrator\Services;

use Curacel\LlmOrchestrator\Jobs\ProcessExecutionLog;
use Curacel\LlmOrchestrator\Models\ExecutionLog;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class LoggerService
{
    /**
     * Record execution log.
     *
     * @param  array<string, mixed>  $logData
     */
    public function record(array $logData): void
    {
        // Check if logging is enabled and the execution_logs table exists
        if (! config('llm-orchestrator.logging.enabled', true) || ! Schema::hasTable(config('llm-orchestrator.tables.execution_logs'))) {
            return;
        }

        $mechanism = config('llm-orchestrator.logging.mechanism', 'sync');

        if ($mechanism === 'async') {
            $this->recordAsync($logData);

            return;
        }

        $this->recordSync($logData);
    }

    /**
     * Synchronous logging.
     *
     * @param  array<string, mixed>  $logData
     */
    public function recordSync(array $logData): void
    {
        ExecutionLog::create($logData);
    }

    /**
     * Asynchronous logging via queue.
     *
     * @param  array<string, mixed>  $logData
     */
    public function recordAsync(array $logData): void
    {
        $connection = config('llm-orchestrator.logging.queue_connection');

        if (! $connection || $connection === 'default') {
            $connection = Queue::getDefaultDriver();
        }

        ProcessExecutionLog::dispatch($logData)->onConnection($connection);
    }
}
