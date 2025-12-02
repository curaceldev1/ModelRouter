<?php

namespace Curacel\LlmOrchestrator;

use Curacel\LlmOrchestrator\Drivers\AbstractDriver;
use Curacel\LlmOrchestrator\Drivers\ClaudeDriver;
use Curacel\LlmOrchestrator\Drivers\GeminiDriver;
use Curacel\LlmOrchestrator\Drivers\OpenAiDriver;
use Curacel\LlmOrchestrator\Services\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class LlmOrchestratorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish the configuration file.
            $this->publishes([
                __DIR__.'/../config/llm-orchestrator.php' => config_path('llm-orchestrator.php'),
            ], 'config');

            // Publish migrations.
            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'migrations');
        }

        // Load Additional Resources
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/llm-orchestrator.php', 'llm-orchestrator');

        // Register the manager class to use with the facade
        $this->app->singleton(Manager::class, function (Application $app) {
            return new Manager($app);
        });

        // Register an alias for the main class
        $this->app->alias(Manager::class, 'llm');

        // Register LLM Drivers
        $this->registerDrivers();

        // TODO: Register nova resources if Nova is present
    }

    /**
     * Register LLM drivers based on configuration.
     */
    protected function registerDrivers(): void
    {
        $clients = config('llm-orchestrator.clients', []);

        // Map of a built-in driver to their driver classes
        $driversMap = [
            'openai' => OpenAiDriver::class,
            'claude' => ClaudeDriver::class,
            'gemini' => GeminiDriver::class,
        ];

        foreach ($clients as $client => $config) {
            if (! isset($config['driver'])) {
                continue; // skip misconfigured clients
            }

            $driverClass = null;
            $driverKey = $config['driver'];

            if (! is_string($client) || ! is_string($driverKey)) {
                continue;
            }

            // Handle custom drivers - check for 'driver' key
            if ($driverKey === 'custom') {
                // class exists and must be a child of AbstractDriver
                $driverClass = isset($config['via']) && class_exists($config['via']) && is_subclass_of($config['via'], AbstractDriver::class)
                    ? $config['via']
                    : null;
            } elseif (isset($driversMap[$driverKey])) {
                $driverClass = $driversMap[$driverKey];
            }

            if (! $driverClass) {
                continue; // unknown client or missing driver class
            }

            // Bind the driver class to the container for the specified client name
            // This allows resolving the driver later via the container using the client name
            $this->app->bind("llm.client.{$client}", fn ($app) => new $driverClass($client, $config));
        }
    }
}
