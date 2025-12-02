<?php

namespace Curacel\LlmOrchestrator\Drivers;

use Curacel\LlmOrchestrator\DataObjects\Content;
use Curacel\LlmOrchestrator\DataObjects\Message;
use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Curacel\LlmOrchestrator\DataObjects\Tool;
use Curacel\LlmOrchestrator\DataObjects\ToolCall;
use Curacel\LlmOrchestrator\Enums\ContentType;
use Curacel\LlmOrchestrator\Exceptions\MessageValidationException;
use Curacel\LlmOrchestrator\Exceptions\RequestFailedException;
use Illuminate\Support\Facades\Http;

final class ClaudeDriver extends AbstractDriver
{
    /**
     * {@inheritDoc}
     */
    protected function getName(): string
    {
        return 'claude';
    }

    /**
     * Execute the request via Anthropic Claude API.
     */
    protected function execute(Request $request): Response
    {
        $payload = $this->buildRequestPayload($request);

        $url = $this->getBaseUrl().'/v1/messages';

        try {
            $response = Http::timeout($this->getTimeout())
                ->withHeaders($this->getRequestHeaders())
                ->retry($this->getMaxRetries())
                ->post($url, $payload)
                ->throw()
                ->json();

            return $this->transformResponse($response, $request);
        } catch (\Throwable $e) {
            throw new RequestFailedException(
                message: $e->getMessage(),
                context: [
                    'client' => $this->getClient(),
                    'driver' => $this->getName(),
                    'payload' => $payload,
                ]
            );
        }
    }

    /**
     * Get the base URL for Anthropic Claude API.
     */
    protected function getBaseUrl(): string
    {
        return rtrim($this->getConfig('base_url', 'https://api.anthropic.com'), '/');
    }

    /**
     * Get the request headers for Anthropic Claude API.
     */
    protected function getRequestHeaders(): array
    {
        $apiKey = $this->getConfig('api_key');
        $version = $this->getConfig('anthropic_version', '2023-06-01');

        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => $version,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Transform Request to Claude native format.
     */
    protected function buildRequestPayload(Request $request): array
    {
        // Use raw payload if provided.
        if ($request->rawPayload) {
            return $request->rawPayload;
        }

        // Extract system messages from regular messages
        $systemMessages = [];
        $regularMessages = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                // Claude expects system messages to be extracted
                if (is_string($message->content)) {
                    $systemMessages[] = ['type' => 'text', 'text' => $message->content];
                } else {
                    // Handle multimodal system content - only text parts are supported for system messages
                    foreach ($message->getContentParts() as $content) {
                        if ($content->type === ContentType::TEXT) {
                            $systemMessages[] = ['type' => 'text', 'text' => $content->data];
                        }
                    }
                }
            } else {
                $regularMessages[] = $message;
            }
        }

        $payload = [
            'model' => $request->model ?? $this->getDefaultModel(),
            'messages' => array_map(fn (Message $message) => $this->transformMessage($message), $regularMessages),
            'max_tokens' => $request->maxTokens ?? $this->getDefaultMaxTokens(),
        ];

        // Add system parameter if system messages exist
        if (! empty($systemMessages)) {
            $payload['system'] = $systemMessages;
        }

        // Add optional parameters
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        // Add a output format for structured output if type is json_schema
        if ($request->responseFormat && ($request->responseFormat['type'] ?? null) === 'json_schema') {
            $payload['output_format'] = [
                'type' => 'json_schema',
                'schema' => $request->responseFormat['json_schema']['schema'],
            ];
        }

        // Add tools for tools calling - may override output_format if both are set
        if (! empty($request->tools)) {
            $payload['tools'] = $this->transformTools($request->tools);
        }

        // Merge any additional options
        return array_merge($payload, $request->options);
    }

    /**
     * Transform a single Message to Claude format.
     */
    protected function transformMessage(Message $message): array
    {
        $result = ['role' => $message->role];

        // Handle simple string content
        if (is_string($message->content)) {
            $result['content'] = $message->content;

            return $result;
        }

        // Handle multimodal content
        $formatted = [];
        foreach ($message->getContentParts() as $content) {
            $formatted[] = $this->transformContentPart($content);
        }

        $result['content'] = $formatted;

        return $result;
    }

    /**
     * Transform a single Content part to Claude format.
     */
    protected function transformContentPart(Content $content): array
    {
        return match ($content->type) {
            ContentType::TEXT => $this->transformTextContent($content),
            ContentType::IMAGE => $this->transformImageContent($content),
            ContentType::DOCUMENT => $this->transformDocumentContent($content),
            ContentType::AUDIO, ContentType::FILE => throw MessageValidationException::forDriver(
                $this->getName(),
                "{$content->type->value} content is not supported by Claude API in messages"
            ),
        };
    }

    /**
     * Transform text content to Claude format.
     */
    protected function transformTextContent(Content $content): array
    {
        return [
            'type' => 'text',
            'text' => $content->data,
        ];
    }

    /**
     * Transform image content to Claude format.
     */
    protected function transformImageContent(Content $content): array
    {
        // Claude supports URL images directly
        if ($this->isUrl($content->data)) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $content->data,
                ],
            ];
        }

        // Otherwise, handle as base64
        $imageData = $this->normalizeImageInput($content->data);

        // Claude expects raw base64 without data URL prefix
        $mediaType = 'image/jpeg';
        $base64Data = $imageData;

        if (str_starts_with($imageData, 'data:')) {
            if (! preg_match('/data:(image\/[a-z]+);base64,(.+)/', $imageData, $matches)) {
                throw MessageValidationException::forDriver($this->getName(), 'Invalid image data URL format');
            }

            $mediaType = $matches[1];
            $base64Data = $matches[2];
        } elseif (isset($content->metadata['mime_type'])) {
            $mediaType = $content->metadata['mime_type'];
        }

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => $base64Data,
            ],
        ];
    }

    protected function transformDocumentContent(Content $content): array
    {
        // Claude supports URL-based PDFs directly
        if ($this->isUrl($content->data)) {
            return [
                'type' => 'document',
                'source' => [
                    'type' => 'url',
                    'url' => $content->data,
                ],
            ];
        }

        // Otherwise, handle as base64
        $pdfData = $this->normalizeFileInput($content->data);

        return [
            'type' => 'document',
            'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $pdfData,
            ],
        ];
    }

    /**
     * Transform tools to Claude format.
     *
     * @param  array<int, Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function transformTools(array $tools): array
    {
        return array_map(fn (Tool $tool) => [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $tool->toArray()['parameters'],
        ], $tools);
    }

    /**
     * Parse Claude tool call into a ToolCall object.
     *
     * @param  array<string, mixed>  $toolCallData
     */
    protected function parseToolCall(array $toolCallData): ToolCall
    {
        return ToolCall::make(
            id: $toolCallData['id'] ?? '',
            name: $toolCallData['name'] ?? '',
            type: $toolCallData['type'] ?? 'tool_use',
            arguments: $toolCallData['input'] ?? [],
        );
    }

    /**
     * Transform Claude response to standard Response format.
     *
     * @param  array<string, mixed>  $response
     */
    protected function transformResponse(array $response, Request $request): Response
    {
        $content = $response['content'] ?? [];
        $usage = $response['usage'] ?? [];

        // Extract text content and tool calls
        $textContent = '';
        $toolCalls = [];

        foreach ($content as $block) {
            if ($block['type'] === 'text' && $textContent === '') {
                // Only use the first text block
                $textContent = $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = $this->parseToolCall($block);
            }
        }

        // Parse structured output if requested, from first text block or tool call args
        $structuredOutput = null;
        if ($request->responseFormat) {
            $structuredOutput = $this->parseStructuredOutput($textContent, $request->responseFormat);

            if (! $structuredOutput && ! empty($toolCalls)) {
                $structuredOutput = $toolCalls[0]->arguments ?? null;
            }
        }

        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $totalTokens = $inputTokens + $outputTokens;
        $model = $response['model'] ?? $request->model ?? $this->getDefaultModel();

        return Response::make(
            content: $textContent,
            driver: $this->getName(),
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            cost: $this->calculateCost($inputTokens, $outputTokens, $model),
            metadata: [
                'id' => $response['id'] ?? null,
                'model' => $response['model'] ?? null,
            ],
            finishReason: $response['stop_reason'] ?? null,
            toolCalls: ! empty($toolCalls) ? $toolCalls : null,
            structuredOutput: $structuredOutput,
        );
    }
}
