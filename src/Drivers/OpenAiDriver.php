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

final class OpenAiDriver extends AbstractDriver
{
    /**
     * {@inheritDoc}
     */
    protected function getName(): string
    {
        return 'openai';
    }

    /**
     * Execute the request via OpenAI API.
     */
    protected function execute(Request $request): Response
    {
        $payload = $this->buildRequestPayload($request);

        $url = $this->getBaseUrl().'/v1/chat/completions';

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
     * Get the base URL for OpenAI API.
     */
    protected function getBaseUrl(): string
    {
        return rtrim($this->getConfig('base_url', 'https://api.openai.com'), '/');
    }

    /**
     * Get the request headers for OpenAI API.
     */
    protected function getRequestHeaders(): array
    {
        $apiKey = $this->getConfig('api_key');

        return [
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Transform Request to OpenAI native format.
     */
    protected function buildRequestPayload(Request $request): array
    {
        // Use raw payload if provided.
        if ($request->rawPayload) {
            return $request->rawPayload;
        }

        $payload = [
            'model' => $request->model ?? $this->getDefaultModel(),
            'messages' => array_map(fn (Message $message) => $this->transformMessage($message), $request->messages),
        ];

        // Add optional parameters
        if ($request->maxTokens !== null) {
            $payload['max_completion_tokens'] = $request->maxTokens;
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        // Add a response format for structured output
        if ($request->responseFormat) {
            $payload['response_format'] = $request->responseFormat;
        }

        // Add tools for tool calling - may override a response format if both are set
        if (! empty($request->tools)) {
            $payload['tools'] = $this->transformTools($request->tools);
        }

        // Merge any additional options
        return array_merge($payload, $request->options);
    }

    /**
     * Transform a single Message to OpenAI format.
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
     * Transform a single Content part to OpenAI format.
     */
    protected function transformContentPart(Content $content): array
    {
        return match ($content->type) {
            ContentType::TEXT => $this->transformTextContent($content),
            ContentType::IMAGE => $this->transformImageContent($content),
            ContentType::AUDIO => $this->transformAudioContent($content),
            ContentType::FILE, ContentType::DOCUMENT => $this->transformFileContent($content),
        };
    }

    /**
     * Transform text content to OpenAI format.
     */
    protected function transformTextContent(Content $content): array
    {
        return [
            'type' => 'text',
            'text' => $content->data,
        ];
    }

    /**
     * Transform image content to OpenAI format.
     */
    protected function transformImageContent(Content $content): array
    {
        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => $this->normalizeImageInput($content->data),
                'detail' => $content->metadata['detail'] ?? 'auto',
            ],
        ];
    }

    /**
     * Transform audio content to OpenAI format.
     */
    protected function transformAudioContent(Content $content): array
    {
        return [
            'type' => 'input_audio',
            'input_audio' => [
                'data' => $this->normalizeFileInput($content->data),
                'format' => $this->resolveAudioFormat($content),
            ],
        ];
    }

    protected function resolveAudioFormat(Content $content): string
    {
        if (isset($content->metadata['format'])) {
            return strtolower($content->metadata['format']);
        }

        throw MessageValidationException::forDriver($this->getName(), 'format must be specified in audio content metadata');
    }

    /**
     * Transform file content to OpenAI format.
     */
    protected function transformFileContent(Content $content): array
    {
        $file = [];

        if (isset($content->metadata['file_id'])) {
            // Existing file ID
            $file['file_id'] = $content->metadata['file_id'];
        } else {
            // Base64 or file path to base64
            $file['file_data'] = $this->normalizeFileInput($content->data);
            $file['filename'] = $content->metadata['filename'] ?? 'uploaded-file';
        }

        return [
            'type' => 'file',
            'file' => $file,
        ];
    }

    /**
     * Transform tools to OpenAI format.
     *
     * @param  array<int, Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function transformTools(array $tools): array
    {
        return array_map(fn (Tool $tool) => [
            'type' => 'function',
            'function' => $tool->toArray(),
        ], $tools);
    }

    /**
     * Parse OpenAI tool call into a ToolCall object.
     *
     * @param  array<string, mixed>  $toolCallData
     */
    protected function parseToolCall(array $toolCallData): ToolCall
    {
        $arguments = $toolCallData['function']['arguments'] ?? [];

        // OpenAI returns arguments as JSON string, decode it
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true) ?? [];
        }

        return ToolCall::make(
            id: $toolCallData['id'] ?? '',
            name: $toolCallData['function']['name'] ?? '',
            type: $toolCallData['type'] ?? 'function',
            arguments: $arguments,
        );
    }

    /**
     * Transform OpenAI response to standard Response format.
     *
     * @param  array<string, mixed>  $response
     */
    protected function transformResponse(array $response, Request $request): Response
    {
        $choice = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $usage = $response['usage'] ?? [];

        // Parse tool calls if present
        $toolCalls = [];
        if (! empty($message['tool_calls'])) {
            $toolCalls = array_map(fn ($toolCall) => $this->parseToolCall($toolCall), $message['tool_calls']);
        }

        // Parse structured output if present and a response format was requested or use tool call arguments as fallback if available
        $structuredOutput = null;
        if ($request->responseFormat && ! empty($message['content'])) {
            $structuredOutput = $this->parseStructuredOutput($message['content'], $request->responseFormat);

            if (! $structuredOutput && ! empty($toolCalls)) {
                $structuredOutput = $toolCalls[0]->arguments ?? null;
            }
        }

        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;
        $totalTokens = $usage['total_tokens'] ?? 0;
        $model = $response['model'] ?? $request->model ?? $this->getDefaultModel();

        return Response::make(
            content: $message['content'] ?? '',
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
            finishReason: $choice['finish_reason'] ?? null,
            toolCalls: $toolCalls,
            structuredOutput: $structuredOutput,
        );
    }
}
