<?php

namespace Curacel\LlmOrchestrator\Drivers;

use Curacel\LlmOrchestrator\DataObjects\Message;
use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Curacel\LlmOrchestrator\DataObjects\Tool;
use Curacel\LlmOrchestrator\DataObjects\ToolCall;
use Curacel\LlmOrchestrator\Enums\ContentType;
use Curacel\LlmOrchestrator\Exceptions\LlmOrchestratorException;
use Curacel\LlmOrchestrator\Exceptions\MessageValidationException;
use Curacel\LlmOrchestrator\Exceptions\RequestFailedException;
use Curacel\LlmOrchestrator\Services\LoggerService;
use Curacel\LlmOrchestrator\Services\MetricsService;

abstract class AbstractDriver
{
    /**
     * Logger service instance.
     */
    protected LoggerService $logger;

    /**
     * Metrics service instance.
     */
    protected MetricsService $metrics;

    /**
     * Initialize the driver.
     *
     * @param  string  $client  Client name
     * @param  array<string, mixed>  $config  Driver configuration
     */
    public function __construct(protected string $client, protected array $config)
    {
        $this->logger = app(LoggerService::class);
        $this->metrics = app(MetricsService::class);
    }

    /**
     * Get the name of the driver.
     */
    abstract protected function getName(): string;

    /**
     * Execute the request via the driver.
     *
     * @throws LlmOrchestratorException
     */
    abstract protected function execute(Request $request): Response;

    public function send(Request $request): Response
    {
        try {
            $response = $this->execute($request);

            // Record logs and metrics (successful request)
            $this->logger->record($this->prepareLogData($request, $response));
            $this->metrics->record($this->prepareMetricsData($request, $response));

            // Return the response
            return $response;
        } catch (LlmOrchestratorException $exception) {
            // Record logs and metrics for failed requests. This excludes non-request failures.
            if ($exception instanceof RequestFailedException) {
                // Record logs and metrics (successful failed)
                $this->logger->record($this->prepareLogData(request: $request, failedReason: $exception->getMessage()));
                $this->metrics->record($this->prepareMetricsData($request));
            }

            throw $exception;
        }
    }

    /**
     * Get configuration value.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get the client name.
     */
    protected function getClient(): string
    {
        return $this->client;
    }

    /**
     * Get the timeout setting.
     */
    protected function getTimeout(): int
    {
        return $this->getConfig('timeout', config('llm-orchestrator.default.timeout'));
    }

    /**
     * Get the maximum retries setting.
     */
    protected function getMaxRetries(): int
    {
        return $this->getConfig('max_retries', config('llm-orchestrator.default.max_retries'));
    }

    /**
     * Get the LLM default model.
     */
    protected function getDefaultModel(): string
    {
        return $this->getConfig('model', config('llm-orchestrator.default.model'));
    }

    /**
     * Get the default max tokens setting.
     */
    protected function getDefaultMaxTokens(): int
    {
        return $this->getConfig('max_tokens', config('llm-orchestrator.default.max_tokens'));
    }

    /**
     * Prepare the metrics data for a request and response.
     *
     * @return array<string, mixed>
     */
    protected function prepareMetricsData(Request $request, ?Response $response = null): array
    {
        $inputTokens = $response->inputTokens ?? 0;
        $outputTokens = $response->outputTokens ?? 0;

        return [
            'date' => now()->toDateString(),
            'client' => $this->getClient(),
            'driver' => $this->getName(),
            'model' => $response->model ?? $request->model ?? $this->getDefaultModel(),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'is_successful' => $response !== null,
            'cost' => $response->cost ?? 0,
        ];
    }

    /**
     * Prepare the log data for the request and response.
     *
     * @return array<string, mixed>
     */
    protected function prepareLogData(Request $request, ?Response $response = null, ?string $failedReason = null): array
    {
        return [
            'client' => $this->getClient(),
            'driver' => $this->getName(),
            'model' => $request->model ?? $this->getDefaultModel(),
            'input_tokens' => $response->inputTokens ?? 0,
            'output_tokens' => $response->outputTokens ?? 0,
            'total_tokens' => $response->totalTokens ?? 0,
            'cost' => $response?->cost,
            'is_successful' => $response !== null,
            'finish_reason' => $response?->finishReason,
            'request_data' => $this->prepareRequestDataForLogging($request),
            'response_data' => $response ? $this->prepareResponseDataForLogging($response) : null,
            'metadata' => array_merge([],
                $failedReason ? ['failed_reason' => $failedReason] : []
            ),
        ];
    }

    /**
     * Prepare request data for logging.
     *
     * @return array<string, mixed>
     */
    protected function prepareRequestDataForLogging(Request $request): array
    {
        return [
            'model' => $request->model ?? $this->getDefaultModel(),
            'messages' => array_map(fn (Message $message) => [
                'role' => $message->role,
                'content' => $this->sanitizeMessageContentForLogging($message),
            ], $request->messages),
            'tools' => array_map(fn (Tool $tool) => $tool->toArray(), $request->tools),
        ];
    }

    /**
     * Prepare response data for logging.
     *
     * @return array<string, mixed>
     */
    protected function prepareResponseDataForLogging(Response $response): array
    {
        return [
            'model' => $response->model,
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => $this->truncate($response->content),
                ],
            ],
            'tool_calls' => array_map(fn (ToolCall $toolCall) => [
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'type' => $toolCall->type,
                'arguments' => $toolCall->arguments,
            ], $response->toolCalls ?? []),
            'structured_output' => $response->structuredOutput,
        ];
    }

    /**
     * Sanitize message content for logging.
     */
    protected function sanitizeMessageContentForLogging(Message $message): array
    {
        $parts = [];

        foreach ($message->getContentParts() as $content) {
            switch ($content->type) {
                case ContentType::TEXT:
                    $parts[] = $this->truncate($content->data);
                    break;

                case ContentType::IMAGE:
                    $parts[] = '[image]';
                    break;

                case ContentType::AUDIO:
                    $parts[] = '[audio]';
                    break;

                case ContentType::FILE:
                    $parts[] = '[file]';
                    break;

                case ContentType::DOCUMENT:
                    $parts[] = '[document]';
                    break;
            }
        }

        return $parts;
    }

    /**
     * Truncate a string to a specified limit.
     */
    protected function truncate(?string $text, int $limit = 500): ?string
    {
        if (! $text) {
            return $text;
        }

        return strlen($text) > $limit ? substr($text, 0, $limit).'... [truncated]' : $text;
    }

    /**
     * Check if a string is valid base64.
     */
    protected function isBase64(string $data): bool
    {
        // Remove any whitespace
        $data = trim($data);

        // Check if it's a valid base64 string
        if (! preg_match('/^[a-zA-Z0-9+\/]*={0,2}$/', $data)) {
            return false;
        }

        // Verify by encoding/decoding
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }

        return base64_encode($decoded) === $data;
    }

    /**
     * Calculate the cost of the request based on token usage and model pricing.
     */
    protected function calculateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? $this->getDefaultModel();
        // Get the client name from the driver instance
        $client = $this->getClient();

        // Try to fetch the model pricing from client-specific config
        $clientModels = config("llm-orchestrator.models.{$client}", []);

        // If client does not have model pricing, fallback to driver key (e.g., 'openai')
        if (! isset($clientModels[$model])) {
            $driverKey = $this->getName();
            $clientModels = config("llm-orchestrator.models.{$driverKey}", []);
        }

        $pricing = $clientModels[$model] ?? ['input' => 0, 'output' => 0];

        return ($inputTokens / 1_000_000 * $pricing['input']) + ($outputTokens / 1_000_000 * $pricing['output']);
    }

    /**
     * Parse structured output from response content.
     *
     * @param  array<string, mixed>  $responseFormat
     * @return array<string, mixed>|null
     */
    protected function parseStructuredOutput(string $content, array $responseFormat): ?array
    {
        $type = $responseFormat['type'] ?? null;

        if ($type !== 'json_object' && ! isset($responseFormat['json_schema'])) {
            return null;
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Normalize image input to a standard format (URL, data URL, or base64).
     */
    protected function normalizeImageInput(string $data): string
    {
        // 1. URL -> return as it is. Driver may fetch and convert later if needed.
        if ($this->isUrl($data)) {
            return $data;
        }

        // If it's a data URL, return as it is
        if (str_starts_with($data, 'data:image/')) {
            // quick sanity check
            if (! preg_match('/^data:image\/[a-z0-9.+-]+;base64,[a-zA-Z0-9+\/]+=*$/', $data)) {
                throw MessageValidationException::forDriver($this->getName(), 'Invalid image data URL format');
            }

            return $data;
        }

        // If it's a file path, read and convert to base64 data URL
        if (file_exists($data) && is_readable($data)) {
            $bytes = @file_get_contents($data);
            if ($bytes === false) {
                throw MessageValidationException::forDriver($this->getName(), "Cannot read image file: {$data}");
            }

            $mime = function_exists('mime_content_type') ? @mime_content_type($data) : null;
            $mime = $mime ?: $this->guessImageMimeFromExtension($data) ?: 'image/jpeg';

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        }

        // If it’s already a raw base64 string, convert to data URL assuming JPEG
        if ($this->isBase64($data)) {
            return 'data:image/jpeg;base64,'.$data;
        }

        throw MessageValidationException::forDriver(
            $this->getName(),
            'Invalid image input: must be a URL, data URL, base64 string, or an existing readable file path'
        );
    }

    /**
     * Extract MIME type and raw base64 payload from a data URL or a raw base64 string.
     */
    protected function extractMimeAndBase64(string $input, string $defaultMime = 'application/octet-stream'): array
    {
        $input = trim($input);

        // If input is a data URL
        if (str_starts_with($input, 'data:')) {
            if (! preg_match('/^data:([a-z0-9\/.+-]+);base64,(.+)$/i', $input, $matches)) {
                throw MessageValidationException::forDriver($this->getName(), 'Invalid data URL format');
            }

            return [$matches[1], $matches[2]];
        }

        // If input is raw base64
        if ($this->isBase64($input)) {
            return [$defaultMime, $input];
        }

        throw MessageValidationException::forDriver($this->getName(), 'Input must be a valid data URL or base64 string');
    }

    /**
     * Guess mime type from file extension.
     */
    protected function guessImageMimeFromExtension(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'tiff', 'tif' => 'image/tiff',
            'ico' => 'image/x-icon',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
    }

    /**
     * Check if a string is a valid URL.
     */
    protected function isUrl(string $value): bool
    {
        if (! preg_match('/^https?:\/\//i', $value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Normalize generic file input to base64 string.
     */
    protected function normalizeFileInput(string $data): string
    {
        // If it's a file path, read and convert to base64
        if (file_exists($data) && is_readable($data)) {
            $bytes = file_get_contents($data);
            if ($bytes === false) {
                throw MessageValidationException::forDriver($this->getName(), "Cannot read file: {$data}");
            }

            return base64_encode($bytes);
        }

        // If it’s already base64, assume it's valid PDF data
        if ($this->isBase64($data)) {
            return $data;
        }

        throw MessageValidationException::forDriver($this->getName(), 'Invalid file input: must be URL, base64, or existing file');
    }
}
