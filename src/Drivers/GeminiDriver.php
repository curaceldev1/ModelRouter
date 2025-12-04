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

final class GeminiDriver extends AbstractDriver
{
    /**
     * {@inheritDoc}
     */
    protected function getName(): string
    {
        return 'gemini';
    }

    /**
     * Execute the request via Google Gemini API.
     */
    protected function execute(Request $request): Response
    {
        $payload = $this->buildRequestPayload($request);

        $model = $request->model ?? $this->getDefaultModel();
        $url = $this->getBaseUrl().'/v1beta/models/'.$model.':generateContent';

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
     * Get the base URL for Google Gemini API.
     */
    protected function getBaseUrl(): string
    {
        return rtrim($this->getConfig('base_url', 'https://generativelanguage.googleapis.com'), '/');
    }

    /**
     * Get the request headers for Google Gemini API.
     */
    protected function getRequestHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-goog-api-key' => $this->getConfig('api_key'),
        ];
    }

    /**
     * Transform Request to Gemini native format.
     */
    protected function buildRequestPayload(Request $request): array
    {
        // Use raw payload if provided.
        if ($request->rawPayload) {
            return $request->rawPayload;
        }

        // Extract system instructions from system messages
        $systemInstruction = null;
        $regularMessages = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                // Gemini expects system instructions in a separate field
                if (is_string($message->content)) {
                    $systemInstruction = ['parts' => [['text' => $message->content]]];
                } else {
                    // Handle multimodal system content
                    $parts = [];
                    foreach ($message->getContentParts() as $content) {
                        if ($content->type === ContentType::TEXT) {
                            $parts[] = ['text' => $content->data];
                        }
                    }
                    if (! empty($parts)) {
                        $systemInstruction = ['parts' => $parts];
                    }
                }
            } else {
                $regularMessages[] = $message;
            }
        }

        $payload = [
            'contents' => array_map(fn (Message $message) => $this->transformMessage($message), $regularMessages),
        ];

        // Add system instruction if present
        if ($systemInstruction) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        // Build generation config
        $generationConfig = [];

        if ($request->maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $request->maxTokens;
        }

        if ($request->temperature !== null) {
            $generationConfig['temperature'] = $request->temperature;
        }

        // Add response format for structured output (JSON schema)
        if ($request->responseFormat && ($request->responseFormat['type'] ?? null) === 'json_schema') {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseJsonSchema'] = $request->responseFormat['json_schema']['schema'];
        } elseif ($request->responseFormat && ($request->responseFormat['type'] ?? null) === 'json_object') {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        if (! empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        // Add tools for function calling
        if (! empty($request->tools)) {
            $payload['tools'] = [
                ['functionDeclarations' => $this->transformTools($request->tools)],
            ];
        }

        // Merge any additional options
        return array_merge($payload, $request->options);
    }

    /**
     * Transform a single Message to Gemini format.
     */
    protected function transformMessage(Message $message): array
    {
        // Gemini uses 'user' and 'model' roles instead of 'assistant'
        $role = $message->role === 'assistant' ? 'model' : $message->role;

        // Handle simple string content
        if (is_string($message->content)) {
            return [
                'role' => $role,
                'parts' => [['text' => $message->content]],
            ];
        }

        // Handle multimodal content
        $parts = [];
        foreach ($message->getContentParts() as $content) {
            $parts[] = $this->transformContentPart($content);
        }

        return [
            'role' => $role,
            'parts' => $parts,
        ];
    }

    /**
     * Transform a single Content part to Gemini format.
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
     * Transform text content to Gemini format.
     */
    protected function transformTextContent(Content $content): array
    {
        return ['text' => $content->data];
    }

    /**
     * Transform image content to Gemini format.
     */
    protected function transformImageContent(Content $content): array
    {
        $raw = $content->data;
        $defaultMimeType = $content->metadata['mime_type'] ?? 'image/jpeg';

        // If input is a URL, normalizeImageInput returns it (we'll convert to data URL).
        $normalizedImage = $this->normalizeImageInput($raw);

        // If it's still a URL
        if ($this->isUrl($normalizedImage)) {
            if (! empty($content->metadata['allow_url']) && $content->metadata['allow_url'] === true) {
                return [
                    'inlineData' => [
                        'mimeType' => $defaultMimeType,
                        'data' => $this->normalizeImageInputAsBase64FromUrl($normalizedImage),
                    ],
                ];
            }

            throw MessageValidationException::forDriver(
                $this->getName(),
                'Gemini driver requires base64/image file for URLs unless metadata.allow_url is true'
            );
        }

        // At this point normalized is a data URL
        [$mimeType, $base64] = $this->extractMimeAndBase64($normalizedImage, $defaultMimeType);

        return [
            'inlineData' => [
                'mimeType' => $mimeType ?? $defaultMimeType,
                'data' => $base64,
            ],
        ];
    }

    /**
     * Helper to fetch remote URL and convert to base64.
     */
    protected function normalizeImageInputAsBase64FromUrl(string $url): string
    {
        try {
            $ctx = stream_context_create(['http' => ['timeout' => $this->getTimeout()]]);
            $bytes = @file_get_contents($url, false, $ctx);
            if ($bytes === false) {
                throw new \RuntimeException("Failed to fetch image from URL: {$url}");
            }

            return base64_encode($bytes);
        } catch (\Throwable $e) {
            throw MessageValidationException::forDriver($this->getName(), "Failed to fetch image URL: {$e->getMessage()}");
        }
    }

    /**
     * Transform audio content to Gemini format.
     */
    protected function transformAudioContent(Content $content): array
    {
        // Normalize audio data to base64
        $audioData = $this->normalizeFileInput($content->data);

        // Determine MIME type from metadata
        $mimeType = $content->metadata['mime_type'] ?? 'audio/wav';

        return [
            'inlineData' => [
                'mimeType' => $mimeType,
                'data' => $audioData,
            ],
        ];
    }

    /**
     * Transform file/document content to Gemini format.
     */
    protected function transformFileContent(Content $content): array
    {
        // Normalize file data to base64
        $fileData = $this->normalizeFileInput($content->data);

        // Determine MIME type from metadata
        $mimeType = $content->metadata['mime_type'] ?? 'application/pdf';

        return [
            'inlineData' => [
                'mimeType' => $mimeType,
                'data' => $fileData,
            ],
        ];
    }

    /**
     * Transform tools to Gemini format.
     *
     * @param  array<int, Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function transformTools(array $tools): array
    {
        return array_map(fn (Tool $tool) => [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->toArray()['parameters'],
        ], $tools);
    }

    /**
     * Parse Gemini function call into a ToolCall object.
     *
     * @param  array<string, mixed>  $functionCall
     */
    protected function parseFunctionCall(array $functionCall): ToolCall
    {
        return ToolCall::make(
            id: $functionCall['id'] ?? $functionCall['name'],
            name: $functionCall['name'] ?? '',
            arguments: $functionCall['args'] ?? [],
        );
    }

    /**
     * Transform Gemini response to standard Response format.
     *
     * @param  array<string, mixed>  $response
     */
    protected function transformResponse(array $response, Request $request): Response
    {
        $candidates = $response['candidates'] ?? [];
        $usageMetadata = $response['usageMetadata'] ?? [];

        // Extract the first candidate's content
        $candidate = $candidates[0] ?? [];
        $content = $candidate['content'] ?? [];
        $parts = $content['parts'] ?? [];

        // Extract text content and function calls
        $textContent = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text']) && $textContent === '') {
                // Only use the first text part
                $textContent = $part['text'];
            } elseif (isset($part['functionCall'])) {
                $toolCalls[] = $this->parseFunctionCall($part['functionCall']);
            }
        }

        // Parse structured output if requested
        $structuredOutput = null;
        if ($request->responseFormat) {
            $structuredOutput = $this->parseStructuredOutput($textContent, $request->responseFormat);

            if (! $structuredOutput && ! empty($toolCalls)) {
                $structuredOutput = $toolCalls[0]->arguments ?? null;
            }
        }

        $inputTokens = $usageMetadata['promptTokenCount'] ?? 0;
        $outputTokens = $usageMetadata['candidatesTokenCount'] ?? 0;
        $totalTokens = $usageMetadata['totalTokenCount'] ?? ($inputTokens + $outputTokens);
        $model = $request->model ?? $this->getDefaultModel();

        return Response::make(
            content: $textContent,
            driver: $this->getName(),
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            cost: $this->calculateCost($inputTokens, $outputTokens, $model),
            metadata: [
                'model' => $model,
            ],
            finishReason: $candidate['finishReason'] ?? null,
            toolCalls: ! empty($toolCalls) ? $toolCalls : null,
            structuredOutput: $structuredOutput,
        );
    }
}
