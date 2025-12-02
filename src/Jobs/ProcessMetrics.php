<?php

namespace Curacel\LlmOrchestrator\Jobs;

use Curacel\LlmOrchestrator\Services\MetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

class ProcessMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly array $metricsData) {}

    public function handle(): void
    {
        App::make(MetricsService::class)->recordSync($this->metricsData);
    }
}
