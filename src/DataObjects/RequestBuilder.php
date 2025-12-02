<?php

namespace Curacel\LlmOrchestrator\DataObjects;

final class RequestBuilder
{
    private ?string $model = null;

    private ?int $maxTokens = null;

    private ?float $temperature = null;

    /** @var array<int, Message> */
    private array $messages = [];

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, Tool> */
    private array $tools = [];

    /** @var array<string, mixed>|null */
    private ?array $responseFormat = null;

    /** @var array<string, mixed>|null */
    private ?array $rawPayload = null;

    public function __construct() {}

    /**
     * Add a user message with the given prompt.
     */
    public function prompt(string $prompt): self
    {
        $this->messages[] = Message::make(role: 'user', content: $prompt);

        return $this;
    }

    /**
     * Set the model identifier.
     */
    public function model(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Set the maximum number of tokens to generate.
     */
    public function maxTokens(?int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    /**
     * Set the temperature for response randomness.
     */
    public function temperature(?float $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    /**
     * Set additional provider-specific options.
     *
     * @param  array<string, mixed>  $options
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Add a message to the conversation.
     */
    public function addMessage(Message $message): self
    {
        $this->messages[] = $message;

        return $this;
    }

    /**
     * Add multiple messages to the conversation.
     *
     * @param  array<int, Message>  $messages
     */
    public function addMessages(array $messages): self
    {
        $this->messages = array_merge($this->messages, $messages);

        return $this;
    }

    /**
     * Set the raw payload to send it directly to the provider API.
     *
     * This overrides other builder parameters.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withRawPayload(array $payload): self
    {
        $this->rawPayload = $payload;

        return $this;
    }

    /**
     * Set a custom response format.
     *
     * @param  array<string, mixed>|null  $responseFormat
     */
    public function withResponseFormat(?array $responseFormat): self
    {
        $this->responseFormat = $responseFormat;

        return $this;
    }

    /**
     * Request JSON output with a specific schema.
     */
    public function asStructuredOutput(Schema $schema): self
    {
        $this->responseFormat = [
            'type' => 'json_schema',
            'json_schema' => $schema->toArray(),
        ];

        return $this;
    }

    /**
     * Request JSON output (without custom json schema).
     */
    public function asJson(): self
    {
        $this->responseFormat = ['type' => 'json_object'];

        return $this;
    }

    /**
     * Add a tool to the request.
     */
    public function addTool(Tool $tool): self
    {
        $this->tools[] = $tool;

        return $this;
    }

    /**
     * Add multiple tools to the request.
     *
     * @param  array<int, Tool>  $tools
     */
    public function addTools(array $tools): self
    {
        $this->tools = array_merge($this->tools, $tools);

        return $this;
    }

    /**
     * Build and return the request object.
     */
    public function build(): Request
    {
        return new Request(
            model: $this->model,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            messages: $this->messages,
            tools: $this->tools,
            options: $this->options,
            responseFormat: $this->responseFormat,
            rawPayload: $this->rawPayload,
        );
    }
}
