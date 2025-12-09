<?php

namespace Curacel\LlmOrchestrator\Services;

use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\RequestBuilder;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Curacel\LlmOrchestrator\Drivers\AbstractDriver;
use Curacel\LlmOrchestrator\Exceptions\AllClientsFailedException;
use Curacel\LlmOrchestrator\Exceptions\InvalidDriverException;
use Curacel\LlmOrchestrator\Exceptions\LlmOrchestratorException;
use Curacel\LlmOrchestrator\Models\ProcessMapping;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class Manager
{
    /**
     * Cached driver instances for clients.
     *
     * @var array<string, AbstractDriver>
     */
    protected array $drivers = [];

    /**
     * Default client name from configuration.
     */
    protected ?string $defaultClient = null;

    /**
     * Context-specific client override.
     */
    protected ?string $contextClient = null;

    /**
     * Initialize the manager.
     */
    public function __construct(protected readonly Application $app)
    {
        $this->defaultClient = config('llm-orchestrator.default.client');
    }

    /**
     * Create a new request builder.
     */
    public function request(): RequestBuilder
    {
        return new RequestBuilder();
    }

    /**
     * Send a simple prompt.
     */
    public function prompt(string $prompt, ?string $client = null): Response
    {
        $request = $this->request()
            ->prompt($prompt)
            ->build();

        return $this->send($request, $client);
    }

    /**
     * Set a context-specific client override.
     *
     * This allows temporarily overriding the default client without changing config.
     */
    public function using(string $client): self
    {
        $this->contextClient = $client;

        return $this;
    }

    /**
     * Send a request to the LLM.
     *
     * @throws LlmOrchestratorException
     */
    public function send(Request $request, ?string $client = null): Response
    {
        $attemptedClients = [];
        $useFallbacks = config('llm-orchestrator.fallback.enabled');
        $client = $client ?? $this->contextClient ?? $this->defaultClient;

        try {
            $attemptedClients[] = $client;
            $driverInstance = $this->resolveDriver($client);

            return $driverInstance->send($request);

        } catch (LlmOrchestratorException $e) {
            if ($useFallbacks) {
                // For fallbacks, if the request contains a specific model to be used.
                // We will remove it to allow fallback clients to use their own default models
                // This is because different clients/providers don't have same model names
                if ($request->model !== null) {
                    $request = $request->withoutModel();
                }

                return $this->sendViaFallbacks($request, $attemptedClients, $e);
            }

            throw $e;
        }
    }

    /**
     * Send a request for a specific process.
     */
    public function forProcess(string $processName, Request $request): Response
    {
        // Check process mapping from config or database
        if (Schema::hasTable(config('llm-orchestrator.tables.process_mappings'))) {
            $processMapping = ProcessMapping::where('process_name', $processName)
                ->where('is_active', true)
                ->first();

            if ($processMapping) {
                // Set the model from the process mapping
                $request = $request->withModel($processMapping->model);

                // Send the request using the mapped client and model
                return $this->send($request, $processMapping->client);
            }
        }

        // Handle process mapping from config if no database record found
        $processMappings = config('llm-orchestrator.process_mappings', []);
        $processMapping = $processMappings[$processName] ?? null;

        if ($processMapping) {
            // Set the model from the process mapping
            $request = $request->withModel($processMapping['model']);

            return $this->send($request, $processMapping['client']);
        }

        // Fallback to default if no specific process mapping found
        return $this->send($request);
    }

    /**
     * Send request via fallback clients if enabled.
     */
    protected function sendViaFallbacks(Request $request, array $attemptedClients, LlmOrchestratorException $lastException): Response
    {
        $errors = [];
        $errors[$attemptedClients[0]] = $lastException->getMessage();

        $fallbackClients = config('llm-orchestrator.fallback.clients', []);

        foreach ($fallbackClients as $client) {
            // Skip already attempted
            if (in_array($client, $attemptedClients)) {
                continue;
            }

            try {
                $driverInstance = $this->resolveDriver($client);

                return $driverInstance->send($request);
            } catch (LlmOrchestratorException $e) {
                $errors[$client] = $e->getMessage();

                $attemptedClients[] = $client;
            }
        }

        throw AllClientsFailedException::afterAttempts($attemptedClients, $errors);
    }

    /**
     * Resolve the driver for the specified client.
     */
    protected function resolveDriver(string $name): AbstractDriver
    {
        // Return a cached driver for the client if available
        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        // Resolve driver from container
        $bindingName = "llm.client.{$name}";

        if (! $this->app->bound($bindingName)) {
            throw new InvalidArgumentException("LLM client [{$name}] is not registered.");
        }

        // Create the driver instance from the container
        $driver = $this->app->make($bindingName);

        if (! $driver instanceof AbstractDriver) {
            throw InvalidDriverException::forDriver($driver, $name);
        }

        // Cache the driver
        $this->drivers[$name] = $driver;

        return $driver;
    }
}
