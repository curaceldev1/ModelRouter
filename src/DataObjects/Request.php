<?php

namespace Curacel\LlmOrchestrator\DataObjects;

final readonly class Request
{
    /**
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $responseFormat
     * @param  array<string, mixed>|null  $rawPayload
     */
    public function __construct(
        public ?string $model = null,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public array $messages = [],
        public array $tools = [],
        public array $options = [],
        public ?array $responseFormat = null,
        public ?array $rawPayload = null,
    ) {}

    /**
     * Create a new RequestBuilder instance.
     */
    public static function make(): RequestBuilder
    {
        return new RequestBuilder;
    }

    /**
     * Create a copy of the request without the model specified.
     */
    public function withoutModel(): self
    {
        $builder = Request::make()
            ->model(null)
            ->addMessages($this->messages)
            ->addTools($this->tools);

        if ($this->maxTokens !== null) {
            $builder->maxTokens($this->maxTokens);
        }

        if ($this->temperature !== null) {
            $builder->temperature($this->temperature);
        }

        if (! empty($this->options)) {
            $builder->options($this->options);
        }

        if ($this->responseFormat !== null) {
            $builder->withResponseFormat($this->responseFormat);
        }

        if ($this->rawPayload !== null) {
            $builder->withRawPayload($this->rawPayload);
        }

        return $builder->build();
    }

    /**
     * Create a copy of the request with a different model specified.
     */
    public function withModel(string $model): self
    {
        $builder = Request::make()
            ->model($model)
            ->addMessages($this->messages)
            ->addTools($this->tools);

        if ($this->maxTokens !== null) {
            $builder->maxTokens($this->maxTokens);
        }

        if ($this->temperature !== null) {
            $builder->temperature($this->temperature);
        }

        if (! empty($this->options)) {
            $builder->options($this->options);
        }

        if ($this->responseFormat !== null) {
            $builder->withResponseFormat($this->responseFormat);
        }

        if ($this->rawPayload !== null) {
            $builder->withRawPayload($this->rawPayload);
        }

        return $builder->build();
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'messages' => array_map(fn (Message $message) => $message->toArray(), $this->messages),
            'tools' => array_map(fn (Tool $tool) => $tool->toArray(), $this->tools),
            'options' => $this->options,
            'response_format' => $this->responseFormat,
            'raw_payload' => $this->rawPayload,
        ];
    }
}
