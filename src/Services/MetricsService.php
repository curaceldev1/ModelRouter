<?php

namespace Curacel\LlmOrchestrator\Services;

use Curacel\LlmOrchestrator\Jobs\ProcessMetrics;
use Curacel\LlmOrchestrator\Models\Metric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class MetricsService
{
    /**
     * Record metrics
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): void
    {
        if (! config('llm-orchestrator.metrics.enabled', true) || ! Schema::hasTable(config('llm-orchestrator.tables.metrics'))) {
            return;
        }

        $mechanism = config('llm-orchestrator.metrics.mechanism', 'sync');

        if ($mechanism === 'async') {
            $this->recordAsync($data);

            return;
        }

        $this->recordSync($data);
    }

    /**
     * Synchronous metrics recording.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordSync(array $data): void
    {
        $this->aggregateMetrics($data);
    }

    /**
     * Asynchronous metrics recording via queue.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordAsync(array $data): void
    {
        $connection = config('llm-orchestrator.metrics.queue_connection');

        if (! $connection || $connection === 'default') {
            $connection = Queue::getDefaultDriver();
        }

        ProcessMetrics::dispatch($data)->onConnection($connection);
    }

    /**
     * Aggregate metrics in the database
     */
    protected function aggregateMetrics(array $data): void
    {
        retry(3, function () use ($data) {
            DB::transaction(function () use ($data) {
                $metrics = Metric::where('date', $data['date'])
                    ->where('client', $data['client'])
                    ->where('driver', $data['driver'])
                    ->where('model', $data['model'])
                    ->lockForUpdate()
                    ->first();

                if ($metrics) {
                    $metrics->incrementEach([
                        'total_requests' => 1,
                        'successful_requests' => $data['is_successful'] === true ? 1 : 0,
                        'failed_requests' => $data['is_successful'] === false ? 1 : 0,
                        'input_tokens' => $data['input_tokens'],
                        'output_tokens' => $data['output_tokens'],
                        'total_tokens' => $data['total_tokens'],
                        'total_cost' => $data['cost'],
                    ]);

                    $metrics->touch();

                    return;
                }

                // Row does not exist → attempt insert
                Metric::create([
                    'date' => $data['date'],
                    'client' => $data['client'],
                    'driver' => $data['driver'],
                    'model' => $data['model'],
                    'total_requests' => 1,
                    'successful_requests' => $data['is_successful'] === true ? 1 : 0,
                    'failed_requests' => $data['is_successful'] === false ? 1 : 0,
                    'input_tokens' => $data['input_tokens'],
                    'output_tokens' => $data['output_tokens'],
                    'total_tokens' => $data['total_tokens'],
                    'total_cost' => $data['cost'],
                ]);
            });
        }, 100);
    }
}
