<?php

namespace Curacel\LlmOrchestrator\DataObjects;

final class Response
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, ToolCall>|null  $toolCalls
     * @param  array<string, mixed>  $structuredOutput
     */
    public function __construct(
        public readonly mixed $content,
        public readonly string $driver,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $totalTokens,
        public readonly ?float $cost,
        public readonly ?array $metadata,
        public readonly ?string $finishReason,
        public readonly ?array $toolCalls,
        public readonly ?array $structuredOutput,
    ) {}

    /**
     * Act as a static factory method.
     *
     * @param  array<int, ToolCall>|null  $toolCalls
     */
    public static function make(
        mixed $content,
        string $driver,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $totalTokens,
        ?float $cost = null,
        ?array $metadata = [],
        ?string $finishReason = null,
        ?array $toolCalls = [],
        ?array $structuredOutput = null,
    ): self {
        return new self(
            content: $content,
            driver: $driver,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            cost: $cost,
            metadata: $metadata,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            structuredOutput: $structuredOutput,
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'driver' => $this->driver,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cost' => $this->cost,
            'metadata' => $this->metadata,
            'finish_reason' => $this->finishReason,
            'tool_calls' => $this->toolCalls ? array_map(fn ($tc) => $tc->toArray(), $this->toolCalls) : null,
            'structured_output' => $this->structuredOutput,
        ];
    }
}
