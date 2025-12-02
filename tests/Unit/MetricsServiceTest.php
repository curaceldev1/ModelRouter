<?php

use Curacel\LlmOrchestrator\Jobs\ProcessMetrics;
use Curacel\LlmOrchestrator\Models\Metric;
use Curacel\LlmOrchestrator\Services\MetricsService;
use Illuminate\Support\Facades\Queue;

describe('MetricsService', function () {
    it('creates new metric record when none exists', function () {
        config()->set('llm-orchestrator.metrics.enabled', true);
        config()->set('llm-orchestrator.metrics.mechanism', 'sync');

        $data = [
            'date' => '2025-12-01',
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'is_successful' => true,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'cost' => 0.0045,
        ];

        app(MetricsService::class)->record($data);

        $this->assertDatabaseHas('llm_metrics', [
            'date' => '2025-12-01',
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'total_requests' => 1,
            'successful_requests' => 1,
            'failed_requests' => 0,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
        ]);
    });

    it('aggregates metrics when record already exists', function () {
        config()->set('llm-orchestrator.metrics.enabled', true);
        config()->set('llm-orchestrator.metrics.mechanism', 'sync');

        // Create initial record
        Metric::create([
            'date' => '2025-12-01',
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o',
            'total_requests' => 1,
            'successful_requests' => 1,
            'failed_requests' => 0,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'total_cost' => 0.01,
        ]);

        $data = [
            'date' => '2025-12-01',
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o',
            'is_successful' => true,
            'input_tokens' => 200,
            'output_tokens' => 100,
            'total_tokens' => 300,
            'cost' => 0.02,
        ];

        app(MetricsService::class)->record($data);

        $metric = Metric::where('date', '2025-12-01')->where('client', 'openai')->first();

        expect($metric->total_requests)->toEqual(2)
            ->and($metric->successful_requests)->toEqual(2)
            ->and($metric->failed_requests)->toEqual(0)
            ->and($metric->input_tokens)->toEqual(300)
            ->and($metric->output_tokens)->toEqual(150)
            ->and($metric->total_tokens)->toEqual(450)
            ->and($metric->total_cost)->toEqual(0.03);
    });

    it('handles successful and failed requests correctly', function () {
        config()->set('llm-orchestrator.metrics.enabled', true);
        config()->set('llm-orchestrator.metrics.mechanism', 'sync');

        $service = app(MetricsService::class);

        // Successful request
        $service->record([
            'date' => '2025-12-01',
            'client' => 'claude',
            'driver' => 'claude',
            'model' => 'claude-3-5-sonnet',
            'is_successful' => true,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'cost' => 0.01,
        ]);

        // Failed request
        $service->record([
            'date' => '2025-12-01',
            'client' => 'claude',
            'driver' => 'claude',
            'model' => 'claude-3-5-sonnet',
            'is_successful' => false,
            'input_tokens' => 10,
            'output_tokens' => 0,
            'total_tokens' => 10,
            'cost' => 0,
        ]);

        $metric = Metric::first();

        expect($metric->total_requests)->toEqual(2)
            ->and($metric->successful_requests)->toEqual(1)
            ->and($metric->failed_requests)->toEqual(1);
    });

    it('dispatches job for async recording', function () {
        config()->set('llm-orchestrator.metrics.enabled', true);
        config()->set('llm-orchestrator.metrics.mechanism', 'async');
        Queue::fake();

        $data = [
            'date' => '2025-12-01',
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'is_successful' => true,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'cost' => 0.0045,
        ];

        app(MetricsService::class)->record($data);

        Queue::assertPushed(ProcessMetrics::class, function ($job) use ($data) {
            return $job->metricsData === $data;
        });
    });

    it('does not record when metrics are disabled', function () {
        config()->set('llm-orchestrator.metrics.enabled', false);

        $data = [
            'date' => '2025-12-01',
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'is_successful' => true,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'cost' => 0.0045,
        ];

        app(MetricsService::class)->record($data);

        expect(Metric::count())->toEqual(0);
    });
});
