<?php

use Curacel\LlmOrchestrator\DataObjects\Content;
use Curacel\LlmOrchestrator\DataObjects\Message;
use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Curacel\LlmOrchestrator\DataObjects\ToolCall;
use Curacel\LlmOrchestrator\Drivers\AbstractDriver;
use Curacel\LlmOrchestrator\Exceptions\MessageValidationException;
use Curacel\LlmOrchestrator\Exceptions\RequestFailedException;
use Curacel\LlmOrchestrator\Services\LoggerService;
use Curacel\LlmOrchestrator\Services\MetricsService;

beforeEach(function () {
    // Mock the logger and metrics services
    $this->loggerMock = Mockery::mock(LoggerService::class);
    $this->metricsMock = Mockery::mock(MetricsService::class);

    app()->instance(LoggerService::class, $this->loggerMock);
    app()->instance(MetricsService::class, $this->metricsMock);

    // Create a concrete test driver implementation
    $this->driver = new class('test-client', ['model' => 'test-model', 'timeout' => 30, 'max_retries' => 3]) extends AbstractDriver
    {
        public function getName(): string
        {
            return 'test-driver';
        }

        public function execute(Request $request): Response
        {
            $defaultModel = invokeProtectedMethod($this, 'getDefaultModel', []);

            return Response::make(
                content: 'Test response',
                driver: $this->getName(),
                model: $request->model ?? $defaultModel,
                inputTokens: 10,
                outputTokens: 20,
                totalTokens: 30,
                cost: 0.001,
                finishReason: 'stop'
            );
        }
    };
});

describe('AbstractDriver - Configuration', function () {
    it('returns the correct client name', function () {
        $client = invokeProtectedMethod($this->driver, 'getClient', []);
        expect($client)->toBe('test-client');
    });

    it('returns the correct driver name', function () {
        expect($this->driver->getName())->toBe('test-driver');
    });

    it('returns configuration values', function () {
        $model = invokeProtectedMethod($this->driver, 'getConfig', ['model']);
        $timeout = invokeProtectedMethod($this->driver, 'getConfig', ['timeout']);
        $default = invokeProtectedMethod($this->driver, 'getConfig', ['nonexistent', 'default']);

        expect($model)->toBe('test-model')
            ->and($timeout)->toBe(30)
            ->and($default)->toBe('default');
    });

    it('returns the default model', function () {
        $model = invokeProtectedMethod($this->driver, 'getDefaultModel', []);
        expect($model)->toBe('test-model');
    });

    it('returns timeout from config', function () {
        $timeout = invokeProtectedMethod($this->driver, 'getTimeout', []);
        expect($timeout)->toBe(30);
    });

    it('falls back to default config when timeout not set', function () {
        config()->set('llm-orchestrator.default.timeout', 60);
        $driver = new class('test-client', []) extends AbstractDriver
        {
            public function getName(): string
            {
                return 'test';
            }

            public function execute(Request $request): Response
            {
                return Response::make('test', 'test', 'test', 0, 0, 0);
            }
        };

        $timeout = invokeProtectedMethod($driver, 'getTimeout', []);
        expect($timeout)->toBe(60);
    });

    it('returns max retries from config', function () {
        $maxRetries = invokeProtectedMethod($this->driver, 'getMaxRetries', []);
        expect($maxRetries)->toBe(3);
    });

    it('falls back to default config when max retries not set', function () {
        config()->set('llm-orchestrator.default.max_retries', 5);
        $driver = new class('test-client', []) extends AbstractDriver
        {
            public function getName(): string
            {
                return 'test';
            }

            public function execute(Request $request): Response
            {
                return Response::make('test', 'test', 'test', 0, 0, 0);
            }
        };

        $maxRetries = invokeProtectedMethod($driver, 'getMaxRetries', []);
        expect($maxRetries)->toBe(5);
    });

    it('returns default max tokens', function () {
        config()->set('llm-orchestrator.default.max_tokens', 1000);
        $maxTokens = invokeProtectedMethod($this->driver, 'getDefaultMaxTokens', []);
        expect($maxTokens)->toBe(1000);
    });
});

describe('AbstractDriver - Request Execution', function () {
    it('successfully sends a request and logs metrics', function () {
        $request = Request::make()->prompt('Hello')->build();

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->content)->toBe('Test response')
            ->and($response->driver)->toBe('test-driver')
            ->and($response->inputTokens)->toBe(10)
            ->and($response->outputTokens)->toBe(20);
    });

    it('logs failed requests when RequestFailedException is thrown', function () {
        $request = Request::make()->prompt('Hello')->build();

        $failingDriver = new class('test-client', []) extends AbstractDriver
        {
            public function getName(): string
            {
                return 'failing-driver';
            }

            public function execute(Request $request): Response
            {
                throw new RequestFailedException('API Error', ['error' => 'timeout']);
            }
        };

        $this->loggerMock->shouldReceive('record')->once()
            ->with(Mockery::on(function ($arg) {
                return $arg['is_successful'] === false
                    && $arg['failed_reason'] === 'API Error';
            }));

        $this->metricsMock->shouldReceive('record')->once()
            ->with(Mockery::on(function ($arg) {
                return $arg['is_successful'] === false;
            }));

        expect(fn () => $failingDriver->send($request))
            ->toThrow(RequestFailedException::class);
    });

    it('does not log non-RequestFailedException errors', function () {
        $request = Request::make()->prompt('Hello')->build();

        $failingDriver = new class('test-client', []) extends AbstractDriver
        {
            public function getName(): string
            {
                return 'failing-driver';
            }

            public function execute(Request $request): Response
            {
                throw new \RuntimeException('Unexpected error');
            }
        };

        // Logger and metrics should NOT be called for non-RequestFailedException
        $this->loggerMock->shouldReceive('record')->never();
        $this->metricsMock->shouldReceive('record')->never();

        expect(fn () => $failingDriver->send($request))->toThrow(\RuntimeException::class);
    });
});

describe('AbstractDriver - Helper Methods', function () {
    it('correctly identifies base64 strings', function () {
        $valid = invokeProtectedMethod($this->driver, 'isBase64', ['SGVsbG8gV29ybGQ=']);
        $invalid1 = invokeProtectedMethod($this->driver, 'isBase64', ['not-base64!']);
        $invalid2 = invokeProtectedMethod($this->driver, 'isBase64', ['12345']);

        expect($valid)->toBeTrue()
            ->and($invalid1)->toBeFalse()
            ->and($invalid2)->toBeFalse();
    });

    it('truncates long text correctly', function () {
        $longText = str_repeat('a', 600);
        $truncated = invokeProtectedMethod($this->driver, 'truncate', [$longText, 500]);

        expect($truncated)->toEndWith('... [truncated]')
            ->and(strlen($truncated))->toBeLessThan(strlen($longText));
    });

    it('does not truncate short text', function () {
        $shortText = 'Hello World';
        $result = invokeProtectedMethod($this->driver, 'truncate', [$shortText, 500]);

        expect($result)->toBe($shortText);
    });

    it('handles null text in truncate', function () {
        $result = invokeProtectedMethod($this->driver, 'truncate', [null]);
        expect($result)->toBeNull();
    });

    it('calculates cost correctly', function () {
        config()->set('llm-orchestrator.models.test-client.gpt-4', [
            'input' => 30,
            'output' => 60,
        ]);

        $cost = invokeProtectedMethod($this->driver, 'calculateCost', [1000, 500, 'gpt-4']);

        // (1000 / 1_000_000 * 30) + (500 / 1_000_000 * 60) = 0.03 + 0.03 = 0.06
        expect($cost)->toBe(0.06);
    });

    it('falls back to driver config for cost calculation when client config missing', function () {
        config()->set('llm-orchestrator.models.test-driver.gpt-4', [
            'input' => 10,
            'output' => 20,
        ]);

        $cost = invokeProtectedMethod($this->driver, 'calculateCost', [1000, 500, 'gpt-4']);

        // (1000 / 1_000_000 * 10) + (500 / 1_000_000 * 20) = 0.01 + 0.01 = 0.02
        expect($cost)->toBe(0.02);
    });

    it('returns zero cost when model pricing not configured', function () {
        $cost = invokeProtectedMethod($this->driver, 'calculateCost', [1000, 500, 'unknown-model']);

        expect($cost)->toBe(0.0);
    });
});

describe('AbstractDriver - Data Preparation Methods', function () {
    it('prepares metrics data correctly', function () {
        $request = Request::make()->model('gpt-4')->prompt('Hello')->build();
        $response = Response::make(
            content: 'Hi',
            driver: 'test-driver',
            model: 'gpt-4',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            cost: 0.05
        );

        $metrics = invokeProtectedMethod($this->driver, 'prepareMetricsData', [$request, $response]);

        expect($metrics)->toHaveKeys(['date', 'client', 'driver', 'model', 'input_tokens', 'output_tokens', 'total_tokens', 'is_successful',
            'cost'])
            ->and($metrics['client'])->toBe('test-client')
            ->and($metrics['driver'])->toBe('test-driver')
            ->and($metrics['model'])->toBe('gpt-4')
            ->and($metrics['input_tokens'])->toBe(100)
            ->and($metrics['output_tokens'])->toBe(50)
            ->and($metrics['total_tokens'])->toBe(150)
            ->and($metrics['is_successful'])->toEqual(true)
            ->and($metrics['cost'])->toBe(0.05);
    });

    it('prepares metrics data for failed requests', function () {
        $request = Request::make()->prompt('Hello')->build();
        $metrics = invokeProtectedMethod($this->driver, 'prepareMetricsData', [$request, null]);

        expect($metrics['is_successful'])->toEqual(false)
            ->and($metrics['input_tokens'])->toBe(0)
            ->and($metrics['output_tokens'])->toBe(0)
            ->and($metrics['cost'])->toBe(0);
    });

    it('prepares log data correctly', function () {
        $request = Request::make()->model('gpt-4')->prompt('Hello')->build();
        $response = Response::make(
            content: 'Hi',
            driver: 'test-driver',
            model: 'gpt-4',
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            cost: 0.05,
            finishReason: 'stop'
        );

        $logData = invokeProtectedMethod($this->driver, 'prepareLogData', [$request, $response]);

        expect($logData)->toHaveKeys(['client', 'driver', 'model', 'input_tokens', 'output_tokens', 'total_tokens', 'cost', 'is_successful',
            'finish_reason', 'request_data', 'response_data'])
            ->and($logData['is_successful'])->toEqual(true)
            ->and($logData['finish_reason'])->toBe('stop');
    });

    it('prepares log data for failed requests', function () {
        $request = Request::make()->prompt('Hello')->build();
        $logData = invokeProtectedMethod($this->driver, 'prepareLogData', [$request, null, 'API timeout']);

        expect($logData['is_successful'])->toEqual(false)
            ->and($logData['failed_reason'])->toBe('API timeout');
    });

    it('prepares request data for logging correctly', function () {
        $message = Message::make('user', 'Hello world');
        $request = Request::make()->addMessages([$message])->model('gpt-4')->build();

        $requestData = invokeProtectedMethod($this->driver, 'prepareRequestDataForLogging', [$request]);

        expect($requestData)->toHaveKeys(['model', 'messages', 'tools'])
            ->and($requestData['model'])->toBe('gpt-4')
            ->and($requestData['messages'])->toBeArray()
            ->and($requestData['messages'][0]['role'])->toBe('user')
            ->and($requestData['messages'][0]['content'])->toBe(['Hello world']);
    });

    it('prepares response data for logging correctly', function () {
        $response = Response::make(
            content: 'Assistant response',
            driver: 'test-driver',
            model: 'gpt-4',
            inputTokens: 10,
            outputTokens: 20,
            totalTokens: 30,
            cost: 0.01,
            finishReason: 'stop',
            toolCalls: [ToolCall::make('call_123', 'tool_name', 'function', ['arg' => 'value'])],
            structuredOutput: ['key' => 'value']
        );

        $responseData = invokeProtectedMethod($this->driver, 'prepareResponseDataForLogging', [$response]);

        expect($responseData)->toHaveKeys(['model', 'messages', 'tool_calls', 'structured_output'])
            ->and($responseData['model'])->toBe('gpt-4')
            ->and($responseData['messages'][0]['role'])->toBe('assistant')
            ->and($responseData['messages'][0]['content'])->toBe('Assistant response')
            ->and($responseData['tool_calls'][0]['id'])->toBe('call_123')
            ->and($responseData['structured_output'])->toBe(['key' => 'value']);
    });
});

describe('AbstractDriver - Structured Output Parsing', function () {
    it('parses structured output from JSON response', function () {
        $content = '{"name":"John","age":30}';
        $responseFormat = ['type' => 'json_object'];

        $output = invokeProtectedMethod($this->driver, 'parseStructuredOutput', [$content, $responseFormat]);

        expect($output)->toBe(['name' => 'John', 'age' => 30]);
    });

    it('parses structured output with json_schema format', function () {
        $content = '{"title":"Test","count":5}';
        $responseFormat = ['json_schema' => ['type' => 'object']];

        $output = invokeProtectedMethod($this->driver, 'parseStructuredOutput', [$content, $responseFormat]);

        expect($output)->toBe(['title' => 'Test', 'count' => 5]);
    });

    it('returns null for non-JSON structured output', function () {
        $content = 'Not JSON';
        $responseFormat = ['type' => 'json_object'];

        $output = invokeProtectedMethod($this->driver, 'parseStructuredOutput', [$content, $responseFormat]);

        expect($output)->toBeNull();
    });

    it('returns null when response format type is not json', function () {
        $content = '{"test":"data"}';
        $responseFormat = ['type' => 'text'];

        $output = invokeProtectedMethod($this->driver, 'parseStructuredOutput', [$content, $responseFormat]);

        expect($output)->toBeNull();
    });

    it('returns null when response format is empty', function () {
        $content = '{"test":"data"}';
        $responseFormat = [];

        $output = invokeProtectedMethod($this->driver, 'parseStructuredOutput', [$content, $responseFormat]);

        expect($output)->toBeNull();
    });

    it('handles nested JSON structures', function () {
        $content = '{"user":{"name":"Alice","details":{"age":25,"city":"NY"}}}';
        $responseFormat = ['type' => 'json_object'];

        $output = invokeProtectedMethod($this->driver, 'parseStructuredOutput', [$content, $responseFormat]);

        expect($output)->toBe([
            'user' => [
                'name' => 'Alice',
                'details' => [
                    'age' => 25,
                    'city' => 'NY',
                ],
            ],
        ]);
    });

    it('handles arrays in JSON', function () {
        $content = '{"items":["apple","banana","cherry"]}';
        $responseFormat = ['type' => 'json_object'];

        $output = invokeProtectedMethod($this->driver, 'parseStructuredOutput', [$content, $responseFormat]);

        expect($output)->toBe(['items' => ['apple', 'banana', 'cherry']]);
    });

    it('returns null for malformed JSON', function () {
        $content = '{"name":"John",age:30}'; // Missing quotes around age key
        $responseFormat = ['type' => 'json_object'];

        $output = invokeProtectedMethod($this->driver, 'parseStructuredOutput', [$content, $responseFormat]);

        expect($output)->toBeNull();
    });
});

describe('AbstractDriver - Image Normalization', function () {
    it('normalizes HTTP URL correctly', function () {
        $url = 'http://example.com/image.jpg';
        $result = invokeProtectedMethod($this->driver, 'normalizeImageInput', [$url]);
        expect($result)->toBe($url);
    });

    it('normalizes HTTPS URL correctly', function () {
        $url = 'https://example.com/image.jpg';
        $result = invokeProtectedMethod($this->driver, 'normalizeImageInput', [$url]);
        expect($result)->toBe($url);
    });

    it('returns data URL as is', function () {
        $dataUrl = 'data:image/jpeg;base64,/9j/4AAQSkZJRg==';
        $result = invokeProtectedMethod($this->driver, 'normalizeImageInput', [$dataUrl]);
        expect($result)->toBe($dataUrl);
    });

    it('converts file path to base64 data URL', function () {
        // Create a temporary test image file
        $tempImagePath = sys_get_temp_dir().'/test_image_'.uniqid().'.jpg';
        $imageData = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlbaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==');
        file_put_contents($tempImagePath, $imageData);

        $result = invokeProtectedMethod($this->driver, 'normalizeImageInput', [$tempImagePath]);

        expect($result)->toStartWith('data:image/')
            ->and($result)->toContain(';base64,');

        // Clean up
        @unlink($tempImagePath);
    });

    it('converts raw base64 to data URL', function () {
        $base64 = 'SGVsbG8gV29ybGQ='; // Valid base64
        $result = invokeProtectedMethod($this->driver, 'normalizeImageInput', [$base64]);

        expect($result)->toBe('data:image/jpeg;base64,SGVsbG8gV29ybGQ=');
    });

    it('throws exception for invalid image input', function () {
        expect(fn () => invokeProtectedMethod($this->driver, 'normalizeImageInput', ['invalid-data!@#']))
            ->toThrow(MessageValidationException::class);
    });

    it('throws exception for non-existent file path', function () {
        expect(fn () => invokeProtectedMethod($this->driver, 'normalizeImageInput', ['/non/existent/path.jpg']))
            ->toThrow(MessageValidationException::class);
    });
});

describe('AbstractDriver - Message Sanitization', function () {
    it('sanitizes text content for logging', function () {
        $message = Message::make('user', Content::text('Hello world'));
        $result = invokeProtectedMethod($this->driver, 'sanitizeMessageContentForLogging', [$message]);
        expect($result)->toBe(['Hello world']);
    });

    it('sanitizes image content for logging', function () {
        $message = Message::make('user', Content::image('http://example.com/image.jpg'));
        $result = invokeProtectedMethod($this->driver, 'sanitizeMessageContentForLogging', [$message]);
        expect($result)->toBe(['[image]']);
    });

    it('sanitizes audio content for logging', function () {
        $message = Message::make('user', Content::audio('http://example.com/audio.mp3'));
        $result = invokeProtectedMethod($this->driver, 'sanitizeMessageContentForLogging', [$message]);
        expect($result)->toBe(['[audio]']);
    });

    it('sanitizes file content for logging', function () {
        $message = Message::make('user', Content::file('http://example.com/file.pdf'));
        $result = invokeProtectedMethod($this->driver, 'sanitizeMessageContentForLogging', [$message]);
        expect($result)->toBe(['[file]']);
    });

    it('truncates long text content', function () {
        $longText = str_repeat('a', 600);
        $message = Message::make('user', Content::text($longText));
        $result = invokeProtectedMethod($this->driver, 'sanitizeMessageContentForLogging', [$message]);
        expect($result[0])->toEndWith('... [truncated]');
    });

    it('handles multiple content parts', function () {
        $content = [
            Content::text('Hello'),
            Content::image('image.jpg'),
            Content::text('world'),
        ];
        $message = Message::make('user', $content);
        $result = invokeProtectedMethod($this->driver, 'sanitizeMessageContentForLogging', [$message]);
        expect($result)->toBe(['Hello', '[image]', 'world']);
    });
});

describe('AbstractDriver - File and Image Helpers', function () {
    it('extracts mime and base64 from data URL', function () {
        $dataUrl = 'data:image/jpeg;base64,SGVsbG8gV29ybGQ=';
        $result = invokeProtectedMethod($this->driver, 'extractMimeAndBase64', [$dataUrl]);
        expect($result)->toBe(['image/jpeg', 'SGVsbG8gV29ybGQ=']);
    });

    it('extracts mime and base64 from raw base64', function () {
        $base64 = 'SGVsbG8gV29ybGQ=';
        $result = invokeProtectedMethod($this->driver, 'extractMimeAndBase64', [$base64, 'application/octet-stream']);
        expect($result)->toBe(['application/octet-stream', 'SGVsbG8gV29ybGQ=']);
    });

    it('throws exception for invalid data URL', function () {
        expect(fn () => invokeProtectedMethod($this->driver, 'extractMimeAndBase64', ['invalid-data-url']))
            ->toThrow(MessageValidationException::class);
    });

    it('throws exception for invalid base64', function () {
        expect(fn () => invokeProtectedMethod($this->driver, 'extractMimeAndBase64', ['not-base64!']))
            ->toThrow(MessageValidationException::class);
    });

    it('guesses image mime from extension', function () {
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['image.jpg']))->toBe('image/jpeg');
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['image.png']))->toBe('image/png');
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['image.gif']))->toBe('image/gif');
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['image.webp']))->toBe('image/webp');
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['image.bmp']))->toBe('image/bmp');
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['image.svg']))->toBe('image/svg+xml');
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['image.tiff']))->toBe('image/tiff');
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['image.ico']))->toBe('image/x-icon');
        expect(invokeProtectedMethod($this->driver, 'guessImageMimeFromExtension', ['unknown.ext']))->toBeNull();
    });

    it('validates URLs correctly', function () {
        expect(invokeProtectedMethod($this->driver, 'isUrl', ['http://example.com']))->toBeTrue();
        expect(invokeProtectedMethod($this->driver, 'isUrl', ['https://example.com']))->toBeTrue();
        expect(invokeProtectedMethod($this->driver, 'isUrl', ['not-a-url']))->toBeFalse();
    });

    it('normalizes file input from file path', function () {
        $tempFilePath = sys_get_temp_dir().'/test_file_'.uniqid().'.txt';
        file_put_contents($tempFilePath, 'test content');

        $result = invokeProtectedMethod($this->driver, 'normalizeFileInput', [$tempFilePath]);
        expect($result)->toBe(base64_encode('test content'));

        @unlink($tempFilePath);
    });

    it('normalizes file input from base64', function () {
        $base64 = 'SGVsbG8gV29ybGQ=';
        $result = invokeProtectedMethod($this->driver, 'normalizeFileInput', [$base64]);
        expect($result)->toBe($base64);
    });

    it('throws exception for invalid file input', function () {
        expect(fn () => invokeProtectedMethod($this->driver, 'normalizeFileInput', ['invalid-input!@#']))
            ->toThrow(MessageValidationException::class);
    });

    it('throws exception for non-existent file', function () {
        expect(fn () => invokeProtectedMethod($this->driver, 'normalizeFileInput', ['/non/existent/file.txt']))
            ->toThrow(MessageValidationException::class);
    });
});
