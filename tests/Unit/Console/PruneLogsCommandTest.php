<?php

use Carbon\Carbon;
use Curacel\LlmOrchestrator\Models\ExecutionLog;

describe('PruneLogsCommand', function () {
    beforeEach(function () {
        // Ensure we have a clean state for each test
        ExecutionLog::query()->delete();
    });

    it('prunes logs older than specified hours', function () {
        // Create test logs with different timestamps
        $now = Carbon::now();

        // Old logs (should be pruned)
        ExecutionLog::create([
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'is_successful' => true,
            'created_at' => $now->copy()->subHours(25),
        ]);

        ExecutionLog::create([
            'client' => 'claude',
            'driver' => 'claude',
            'model' => 'claude-3-5-sonnet',
            'input_tokens' => 20,
            'output_tokens' => 10,
            'total_tokens' => 30,
            'is_successful' => false,
            'created_at' => $now->copy()->subHours(30),
        ]);

        // Recent logs (should be kept)
        ExecutionLog::create([
            'client' => 'gemini',
            'driver' => 'gemini',
            'model' => 'gemini-1.5-pro',
            'input_tokens' => 15,
            'output_tokens' => 8,
            'total_tokens' => 23,
            'is_successful' => true,
            'created_at' => $now->copy()->subHours(12),
        ]);

        // Verify we have 3 logs initially
        expect(ExecutionLog::count())->toBe(3);

        // Run the prune command to remove logs older than 24 hours
        $this->artisan('llm:prune-logs', ['--hours' => 24])
            ->assertSuccessful();

        // Verify only 1 log remains (the recent one)
        expect(ExecutionLog::count())->toBe(1);

        $remainingLog = ExecutionLog::first();
        expect($remainingLog->client)->toBe('gemini');
    });

    it('prunes all logs when no hours flag provided', function () {
        // Create test logs
        ExecutionLog::create([
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'is_successful' => true,
            'created_at' => Carbon::now()->subHours(1),
        ]);

        ExecutionLog::create([
            'client' => 'claude',
            'driver' => 'claude',
            'model' => 'claude-3-5-sonnet',
            'input_tokens' => 20,
            'output_tokens' => 10,
            'total_tokens' => 30,
            'is_successful' => true,
            'created_at' => Carbon::now()->subDays(30),
        ]);

        // Verify we have 2 logs initially
        expect(ExecutionLog::count())->toBe(2);
        $this->artisan('llm:prune-logs')->assertSuccessful();
        expect(ExecutionLog::count())->toBe(0);
    });

    it('handles case when no logs need pruning', function () {
        // Create a recent log
        ExecutionLog::create([
            'client' => 'openai',
            'driver' => 'openai',
            'model' => 'gpt-4',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'is_successful' => true,
            'created_at' => Carbon::now()->subHour(),
        ]);

        expect(ExecutionLog::count())->toBe(1);

        // Try to prune logs older than 24 hours
        $this->artisan('llm:prune-logs', ['--hours' => 24])
            ->expectsOutputToContain('No logs found to prune.')
            ->assertSuccessful();

        // Verify log still exists
        expect(ExecutionLog::count())->toBe(1);
    });
});
