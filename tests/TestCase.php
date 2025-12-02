<?php

namespace Curacel\LlmOrchestrator\Tests;

use Curacel\LlmOrchestrator\LlmOrchestratorServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base test case for the LLM Orchestrator package.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LlmOrchestratorServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment($app): void
    {
        // Load the package configuration
        $app['config']->set('llm-orchestrator', require __DIR__.'/../config/llm-orchestrator.php');

        // Configure a database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Define database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
