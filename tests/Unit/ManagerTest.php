<?php

use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Curacel\LlmOrchestrator\Drivers\AbstractDriver;
use Curacel\LlmOrchestrator\Exceptions\AllClientsFailedException;
use Curacel\LlmOrchestrator\Exceptions\InvalidDriverException;
use Curacel\LlmOrchestrator\Exceptions\RequestFailedException;
use Curacel\LlmOrchestrator\Models\ProcessMapping;
use Curacel\LlmOrchestrator\Services\Manager;

describe('Manager - Core Behavior', function () {
    beforeEach(function () {
        config()->set('llm-orchestrator.default.client', 'test-client');
        config()->set('llm-orchestrator.fallback.enabled', false);
    });

    it('throws an exception if the requested client is not registered', function () {
        $manager = app(Manager::class);
        $request = Request::make()->prompt('Hello')->build();

        expect(fn () => $manager->send($request, 'nonexistent'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws an exception if the driver instance is invalid', function () {
        app()->bind('llm.client.invalid', fn () => new stdClass);
        $manager = app(Manager::class);
        $request = Request::make()->prompt('Hello')->build();

        expect(fn () => $manager->send($request, 'invalid'))
            ->toThrow(InvalidDriverException::class);
    });

    it('caches driver instances to avoid recreating them on multiple calls', function () {
        $callCount = 0;
        app()->bind('llm.client.cached', function () use (&$callCount) {
            $callCount++;
            $mock = Mockery::mock(AbstractDriver::class);
            $mock->shouldReceive('send')->andReturn(Response::make(
                content: 'Test',
                driver: 'cached',
                model: 'gpt-4',
                inputTokens: 10,
                outputTokens: 5,
                totalTokens: 15,
            ));

            return $mock;
        });

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Hello')->build();

        $manager->send($request, 'cached');
        $manager->send($request, 'cached');

        expect($callCount)->toBe(1);
    });

    it('respects a context-specific client override', function () {
        $mock = Mockery::mock(AbstractDriver::class);
        $mock->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Test',
            driver: 'context-client',
            model: 'gpt-4',
            inputTokens: 10,
            outputTokens: 5,
            totalTokens: 15,
        ));

        app()->bind('llm.client.context-client', fn () => $mock);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Hello')->build();
        $response = $manager->using('context-client')->send($request);

        expect($response->driver)->toBe('context-client');
    });
});

describe('Manager - Fallback Behavior', function () {
    it('successfully falls back to the next client when the primary fails', function () {
        config()->set('llm-orchestrator.default.client', 'primary');
        config()->set('llm-orchestrator.fallback.enabled', true);
        config()->set('llm-orchestrator.fallback.clients', ['fallback-1', 'fallback-2']);

        $primary = Mockery::mock(AbstractDriver::class);
        $primary->shouldReceive('send')->once()
            ->andThrow(new RequestFailedException('Primary failed'));

        $fallback1 = Mockery::mock(AbstractDriver::class);
        $fallback1->shouldReceive('send')->once()
            ->andThrow(new RequestFailedException('Fallback 1 failed'));

        $fallback2 = Mockery::mock(AbstractDriver::class);
        $fallback2->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Fallback success',
            driver: 'fallback-2',
            model: 'gpt-4',
            inputTokens: 10,
            outputTokens: 5,
            totalTokens: 15,
        ));

        app()->bind('llm.client.primary', fn () => $primary);
        app()->bind('llm.client.fallback-1', fn () => $fallback1);
        app()->bind('llm.client.fallback-2', fn () => $fallback2);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Hello')->build();
        $response = $manager->send($request);

        expect($response->content)->toBe('Fallback success');
    });

    it('throws AllClientsFailedException if all fallback clients fail', function () {
        config()->set('llm-orchestrator.default.client', 'primary');
        config()->set('llm-orchestrator.fallback.enabled', true);
        config()->set('llm-orchestrator.fallback.clients', ['fallback']);

        $primary = Mockery::mock(AbstractDriver::class);
        $primary->shouldReceive('send')
            ->andThrow(new RequestFailedException('Primary failed'));

        $fallback = Mockery::mock(AbstractDriver::class);
        $fallback->shouldReceive('send')
            ->andThrow(new RequestFailedException('Fallback failed'));

        app()->bind('llm.client.primary', fn () => $primary);
        app()->bind('llm.client.fallback', fn () => $fallback);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Hello')->build();

        expect(fn () => $manager->send($request))
            ->toThrow(AllClientsFailedException::class);
    });

    it('skips clients that were already attempted in the fallback chain', function () {
        config()->set('llm-orchestrator.default.client', 'test');
        config()->set('llm-orchestrator.fallback.enabled', true);
        config()->set('llm-orchestrator.fallback.clients', ['test', 'fallback']);

        $primary = Mockery::mock(AbstractDriver::class);
        $primary->shouldReceive('send')->once()
            ->andThrow(new RequestFailedException('Failed'));

        $fallback = Mockery::mock(AbstractDriver::class);
        $fallback->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Success',
            driver: 'fallback',
            model: 'gpt-4',
            inputTokens: 10,
            outputTokens: 5,
            totalTokens: 15,
        ));

        app()->bind('llm.client.test', fn () => $primary);
        app()->bind('llm.client.fallback', fn () => $fallback);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Hello')->build();
        $response = $manager->send($request);

        expect($response->content)->toBe('Success');
    });
});

describe('Manager - Process Mapping with forProcess', function () {
    beforeEach(function () {
        config()->set('llm-orchestrator.default.client', 'openai');
        config()->set('llm-orchestrator.tables.process_mappings', 'llm_process_mappings');
        config()->set('llm-orchestrator.fallback.enabled', false);
    });

    afterEach(function () {
        ProcessMapping::query()->delete();
    });

    it('uses process mapping from database when table exists and mapping is active', function () {
        // Create a process mapping
        ProcessMapping::create([
            'process_name' => 'claim_analysis',
            'client' => 'claude',
            'model' => 'claude-3-5-sonnet-20241022',
            'is_active' => true,
        ]);

        $mockDriver = Mockery::mock(AbstractDriver::class);
        $mockDriver->shouldReceive('send')->once()->andReturnUsing(function (Request $request) {
            // Verify the model was set correctly from the process mapping
            expect($request->model)->toBe('claude-3-5-sonnet-20241022');

            return Response::make(
                content: 'Analysis complete',
                driver: 'claude',
                model: 'claude-3-5-sonnet-20241022',
                inputTokens: 100,
                outputTokens: 50,
                totalTokens: 150,
            );
        });

        app()->bind('llm.client.claude', fn () => $mockDriver);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Analyze this claim')->build();
        $response = $manager->forProcess('claim_analysis', $request);

        expect($response->content)->toBe('Analysis complete')
            ->and($response->model)->toBe('claude-3-5-sonnet-20241022')
            ->and($response->driver)->toBe('claude');
    });

    it('skips inactive process mappings in database and falls back to config or default', function () {

        // Create an inactive process mapping
        ProcessMapping::create([
            'process_name' => 'claim_analysis',
            'client' => 'claude',
            'model' => 'claude-3-5-sonnet-20241022',
            'is_active' => false,
        ]);

        $mockDriver = Mockery::mock(AbstractDriver::class);
        $mockDriver->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Default response',
            driver: 'openai',
            model: 'gpt-4o-mini',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
        ));

        app()->bind('llm.client.openai', fn () => $mockDriver);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Analyze this claim')->build();
        $response = $manager->forProcess('claim_analysis', $request);

        // Should use the default client since the mapping is inactive
        expect($response->driver)->toBe('openai');
    });

    it('uses process mapping from config when database mapping does not exist', function () {
        config()->set('llm-orchestrator.process_mappings', [
            'fraud_detection' => [
                'client' => 'gemini',
                'model' => 'gemini-1.5-pro',
            ],
        ]);

        $mockDriver = Mockery::mock(AbstractDriver::class);
        $mockDriver->shouldReceive('send')->once()->andReturnUsing(function (Request $request) {
            expect($request->model)->toBe('gemini-1.5-pro');

            return Response::make(
                content: 'Fraud check complete',
                driver: 'gemini',
                model: 'gemini-1.5-pro',
                inputTokens: 200,
                outputTokens: 100,
                totalTokens: 300,
            );
        });

        app()->bind('llm.client.gemini', fn () => $mockDriver);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Check for fraud')->build();
        $response = $manager->forProcess('fraud_detection', $request);

        expect($response->content)->toBe('Fraud check complete')
            ->and($response->model)->toBe('gemini-1.5-pro')
            ->and($response->driver)->toBe('gemini');
    });

    it('uses process mapping from config when database table does not exist', function () {
        config()->set('llm-orchestrator.process_mappings', [
            'document_classification' => [
                'client' => 'claude',
                'model' => 'claude-3-5-haiku-20241022',
            ],
        ]);

        $mockDriver = Mockery::mock(AbstractDriver::class);
        $mockDriver->shouldReceive('send')->once()->andReturnUsing(function (Request $request) {
            expect($request->model)->toBe('claude-3-5-haiku-20241022');

            return Response::make(
                content: 'Document classified',
                driver: 'claude',
                model: 'claude-3-5-haiku-20241022',
                inputTokens: 150,
                outputTokens: 75,
                totalTokens: 225,
            );
        });

        app()->bind('llm.client.claude', fn () => $mockDriver);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Classify this document')->build();
        $response = $manager->forProcess('document_classification', $request);

        expect($response->content)->toBe('Document classified')
            ->and($response->model)->toBe('claude-3-5-haiku-20241022')
            ->and($response->driver)->toBe('claude');
    });

    it('falls back to default client when no process mapping exists', function () {
        config()->set('llm-orchestrator.process_mappings', []);

        $mockDriver = Mockery::mock(AbstractDriver::class);
        $mockDriver->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Default processing',
            driver: 'openai',
            model: 'gpt-4o-mini',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
        ));

        app()->bind('llm.client.openai', fn () => $mockDriver);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Process this')->build();
        $response = $manager->forProcess('unknown_process', $request);

        expect($response->driver)->toBe('openai');
    });

    it('database process mapping takes precedence over config', function () {

        // Create a database mapping
        ProcessMapping::create([
            'process_name' => 'email_generation',
            'client' => 'claude',
            'model' => 'claude-3-5-sonnet-20241022',
            'is_active' => true,
        ]);

        // Also set a config mapping (should be ignored)
        config()->set('llm-orchestrator.process_mappings', [
            'email_generation' => [
                'client' => 'openai',
                'model' => 'gpt-4o',
            ],
        ]);

        $claudeMock = Mockery::mock(AbstractDriver::class);
        $claudeMock->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Email generated',
            driver: 'claude',
            model: 'claude-3-5-sonnet-20241022',
            inputTokens: 100,
            outputTokens: 200,
            totalTokens: 300,
        ));

        app()->bind('llm.client.claude', fn () => $claudeMock);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Generate email')->build();
        $response = $manager->forProcess('email_generation', $request);

        // Should use database mapping (claude), not config (openai)
        expect($response->driver)->toBe('claude')
            ->and($response->model)->toBe('claude-3-5-sonnet-20241022');
    });

    it('overrides request model with process mapping model from database', function () {

        ProcessMapping::create([
            'process_name' => 'sentiment_analysis',
            'client' => 'openai',
            'model' => 'gpt-4o',
            'is_active' => true,
        ]);

        $mockDriver = Mockery::mock(AbstractDriver::class);
        $mockDriver->shouldReceive('send')->once()->andReturnUsing(function (Request $request) {
            // Model should be overridden to gpt-4o
            expect($request->model)->toBe('gpt-4o');

            return Response::make(
                content: 'Sentiment analyzed',
                driver: 'openai',
                model: 'gpt-4o',
                inputTokens: 50,
                outputTokens: 25,
                totalTokens: 75,
            );
        });

        app()->bind('llm.client.openai', fn () => $mockDriver);

        $manager = app(Manager::class);
        // Request with a different model - should be overridden
        $request = Request::make()
            ->prompt('Analyze sentiment')
            ->model('gpt-4o-mini')
            ->build();

        $response = $manager->forProcess('sentiment_analysis', $request);

        expect($response->model)->toBe('gpt-4o');
    });

    it('overrides request model with process mapping model from config', function () {
        config()->set('llm-orchestrator.process_mappings', [
            'text_summarization' => [
                'client' => 'claude',
                'model' => 'claude-3-5-haiku-20241022',
            ],
        ]);

        $mockDriver = Mockery::mock(AbstractDriver::class);
        $mockDriver->shouldReceive('send')->once()->andReturnUsing(function (Request $request) {
            // Model should be overridden to claude-3-5-haiku-20241022
            expect($request->model)->toBe('claude-3-5-haiku-20241022');

            return Response::make(
                content: 'Summary generated',
                driver: 'claude',
                model: 'claude-3-5-haiku-20241022',
                inputTokens: 300,
                outputTokens: 100,
                totalTokens: 400,
            );
        });

        app()->bind('llm.client.claude', fn () => $mockDriver);

        $manager = app(Manager::class);
        // Request with a different model - should be overridden
        $request = Request::make()
            ->prompt('Summarize this text')
            ->model('claude-3-5-sonnet-20241022')
            ->build();

        $response = $manager->forProcess('text_summarization', $request);

        expect($response->model)->toBe('claude-3-5-haiku-20241022');
    });

    it('uses the correct client from database process mapping', function () {

        ProcessMapping::create([
            'process_name' => 'code_review',
            'client' => 'gemini',
            'model' => 'gemini-1.5-flash',
            'is_active' => true,
        ]);

        $geminiMock = Mockery::mock(AbstractDriver::class);
        $geminiMock->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Code reviewed',
            driver: 'gemini',
            model: 'gemini-1.5-flash',
            inputTokens: 500,
            outputTokens: 200,
            totalTokens: 700,
        ));

        $openaiMock = Mockery::mock(AbstractDriver::class);
        $openaiMock->shouldReceive('send')->never();

        app()->bind('llm.client.gemini', fn () => $geminiMock);
        app()->bind('llm.client.openai', fn () => $openaiMock);

        $manager = app(Manager::class);
        $request = Request::make()->prompt('Review this code')->build();
        $response = $manager->forProcess('code_review', $request);

        // Should use gemini, not the default openai
        expect($response->driver)->toBe('gemini');
    });

    it('handles multiple process mappings independently', function () {
        config()->set('llm-orchestrator.process_mappings', [
            'process_a' => [
                'client' => 'openai',
                'model' => 'gpt-4o',
            ],
            'process_b' => [
                'client' => 'claude',
                'model' => 'claude-3-5-sonnet-20241022',
            ],
        ]);

        $openaiMock = Mockery::mock(AbstractDriver::class);
        $openaiMock->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Process A result',
            driver: 'openai',
            model: 'gpt-4o',
            inputTokens: 50,
            outputTokens: 25,
            totalTokens: 75,
        ));

        $claudeMock = Mockery::mock(AbstractDriver::class);
        $claudeMock->shouldReceive('send')->once()->andReturn(Response::make(
            content: 'Process B result',
            driver: 'claude',
            model: 'claude-3-5-sonnet-20241022',
            inputTokens: 60,
            outputTokens: 30,
            totalTokens: 90,
        ));

        app()->bind('llm.client.openai', fn () => $openaiMock);
        app()->bind('llm.client.claude', fn () => $claudeMock);

        $manager = app(Manager::class);

        $responseA = $manager->forProcess('process_a', Request::make()->prompt('Test A')->build());
        expect($responseA->driver)->toBe('openai');

        $responseB = $manager->forProcess('process_b', Request::make()->prompt('Test B')->build());
        expect($responseB->driver)->toBe('claude');
    });
});
