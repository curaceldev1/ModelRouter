<?php

use Curacel\LlmOrchestrator\Jobs\ProcessExecutionLog;
use Curacel\LlmOrchestrator\Models\ExecutionLog;
use Curacel\LlmOrchestrator\Services\LoggerService;
use Illuminate\Support\Facades\Queue;

describe('LoggerService', function () {
    it('creates execution log synchronously', function () {
        config()->set('llm-orchestrator.logging.enabled', true);
        config()->set('llm-orchestrator.logging.mechanism', 'sync');

        $logData = [
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'request_data' => ['prompt' => 'Hello'],
            'response_data' => ['content' => 'Hi there'],
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'is_successful' => true,
        ];

        app(LoggerService::class)->record($logData);

        $this->assertDatabaseHas('llm_execution_logs', [
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'is_successful' => true,
        ]);
    });

    it('stores request and response data as JSON', function () {
        config()->set('llm-orchestrator.logging.enabled', true);
        config()->set('llm-orchestrator.logging.mechanism', 'sync');

        $logData = [
            'client' => 'claude',
            'driver' => 'claude',
            'model' => 'claude-3-5-sonnet',
            'request_data' => [
                'messages' => [['role' => 'user', 'content' => 'Test']],
                'max_tokens' => 1000,
            ],
            'response_data' => [
                'content' => 'Response content',
                'stop_reason' => 'end_turn',
            ],
            'input_tokens' => 50,
            'output_tokens' => 20,
            'total_tokens' => 70,
            'is_successful' => true,
        ];

        app(LoggerService::class)->record($logData);

        $log = ExecutionLog::first();

        expect($log->request_data)->toBeArray()
            ->and($log->request_data['messages'][0]['role'])->toBe('user')
            ->and($log->response_data)->toBeArray()
            ->and($log->response_data['content'])->toBe('Response content');
    });

    it('records failed requests correctly', function () {
        config()->set('llm-orchestrator.logging.enabled', true);
        config()->set('llm-orchestrator.logging.mechanism', 'sync');

        $logData = [
            'client' => 'gemini',
            'driver' => 'gemini',
            'model' => 'gemini-1.5-pro',
            'request_data' => ['prompt' => 'Test'],
            'response_data' => ['error' => 'Rate limit exceeded'],
            'input_tokens' => 5,
            'output_tokens' => 0,
            'total_tokens' => 5,
            'is_successful' => false,
            'failed_reason' => 'Rate limit exceeded',
        ];

        app(LoggerService::class)->record($logData);

        $this->assertDatabaseHas('llm_execution_logs', [
            'client' => 'gemini',
            'is_successful' => false,
            'failed_reason' => 'Rate limit exceeded',
        ]);
    });

    it('dispatches job for async logging', function () {
        config()->set('llm-orchestrator.logging.enabled', true);
        config()->set('llm-orchestrator.logging.mechanism', 'async');
        Queue::fake();

        $logData = [
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'request_data' => ['prompt' => 'Hello async'],
            'response_data' => ['content' => 'Hi there'],
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'is_successful' => true,
        ];

        app(LoggerService::class)->record($logData);

        Queue::assertPushed(ProcessExecutionLog::class, fn ($job) => $job->logData === $logData);
    });

    it('does not record when logging is disabled', function () {
        config()->set('llm-orchestrator.logging.enabled', false);

        $logData = [
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'request_data' => ['prompt' => 'Hello'],
            'response_data' => ['content' => 'Hi'],
            'input_tokens' => 5,
            'output_tokens' => 2,
            'total_tokens' => 7,
            'is_successful' => true,
        ];

        app(LoggerService::class)->record($logData);

        expect(ExecutionLog::count())->toBe(0);
    });
});
