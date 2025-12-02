<?php

use Curacel\LlmOrchestrator\DataObjects\Content;
use Curacel\LlmOrchestrator\DataObjects\Message;
use Curacel\LlmOrchestrator\DataObjects\Property;
use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Curacel\LlmOrchestrator\DataObjects\Schema;
use Curacel\LlmOrchestrator\DataObjects\Tool;
use Curacel\LlmOrchestrator\DataObjects\ToolCall;
use Curacel\LlmOrchestrator\Drivers\ClaudeDriver;
use Curacel\LlmOrchestrator\Exceptions\MessageValidationException;
use Curacel\LlmOrchestrator\Exceptions\RequestFailedException;
use Curacel\LlmOrchestrator\Services\LoggerService;
use Curacel\LlmOrchestrator\Services\MetricsService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Mock the logger and metrics services
    $this->loggerMock = Mockery::mock(LoggerService::class);
    $this->metricsMock = Mockery::mock(MetricsService::class);

    app()->instance(LoggerService::class, $this->loggerMock);
    app()->instance(MetricsService::class, $this->metricsMock);

    // Create Claude driver instance
    $this->driver = new ClaudeDriver('claude-test', [
        'api_key' => 'test-api-key',
        'anthropic_version' => '2023-06-01',
        'base_url' => 'https://api.anthropic.com',
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 4096,
        'timeout' => 30,
        'max_retries' => 3,
    ]);
});

describe('ClaudeDriver - Basic Configuration', function () {
    it('returns the correct driver name', function () {
        $name = invokeProtectedMethod($this->driver, 'getName');
        expect($name)->toBe('claude');
    });

    it('returns the correct base URL', function () {
        $baseUrl = invokeProtectedMethod($this->driver, 'getBaseUrl');
        expect($baseUrl)->toBe('https://api.anthropic.com');
    });

    it('trims trailing slash from base URL', function () {
        $driver = new ClaudeDriver('claude-test', [
            'api_key' => 'test-key',
            'base_url' => 'https://api.anthropic.com/',
        ]);

        $baseUrl = invokeProtectedMethod($driver, 'getBaseUrl');
        expect($baseUrl)->toBe('https://api.anthropic.com');
    });

    it('generates correct request headers', function () {
        $headers = invokeProtectedMethod($this->driver, 'getRequestHeaders');

        expect($headers)->toHaveKey('x-api-key')
            ->and($headers['x-api-key'])->toBe('test-api-key')
            ->and($headers['anthropic-version'])->toBe('2023-06-01')
            ->and($headers['Content-Type'])->toBe('application/json')
            ->and($headers['Accept'])->toBe('application/json');
    });

    it('uses default anthropic version when not specified', function () {
        $driver = new ClaudeDriver('claude-test', [
            'api_key' => 'test-key',
        ]);

        $headers = invokeProtectedMethod($driver, 'getRequestHeaders');
        expect($headers['anthropic-version'])->toBe('2023-06-01');
    });
});

describe('ClaudeDriver - Request Execution', function () {
    it('successfully executes a simple request', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Hi there!',
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->content)->toBe('Hi there!')
            ->and($response->driver)->toBe('claude')
            ->and($response->model)->toBe('claude-3-5-sonnet-20241022')
            ->and($response->inputTokens)->toBe(10)
            ->and($response->outputTokens)->toBe(5)
            ->and($response->totalTokens)->toBe(15)
            ->and($response->finishReason)->toBe('end_turn');
    });

    it('throws RequestFailedException on HTTP error', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => [
                    'type' => 'authentication_error',
                    'message' => 'Invalid API key',
                ],
            ], 401),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        expect(fn () => $this->driver->send($request))
            ->toThrow(RequestFailedException::class);
    });

    it('retries on failure', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => ['type' => 'overloaded_error', 'message' => 'Server overloaded']], 529)
                ->push(['error' => ['type' => 'overloaded_error', 'message' => 'Server overloaded']], 529)
                ->push([
                    'id' => 'msg_123',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-3-5-sonnet-20241022',
                    'content' => [['type' => 'text', 'text' => 'Success']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->content)->toBe('Success');
    });
});

describe('ClaudeDriver - Message Transformation', function () {
    it('transforms simple text messages correctly', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', 'Hello'),
                Message::make('assistant', 'Hi'),
                Message::make('user', 'How are you?'),
            ])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 20, 'output_tokens' => 5],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $payload['messages'][0]['role'] === 'user'
                && $payload['messages'][0]['content'] === 'Hello'
                && $payload['messages'][1]['role'] === 'assistant'
                && $payload['messages'][1]['content'] === 'Hi'
                && $payload['messages'][2]['role'] === 'user'
                && $payload['messages'][2]['content'] === 'How are you?';
        });
    });

    it('extracts system messages to system parameter', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('system', 'You are a helpful assistant.'),
                Message::make('user', 'Hello'),
            ])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 15, 'output_tokens' => 3],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['system'])
                && is_array($payload['system'])
                && $payload['system'][0]['type'] === 'text'
                && $payload['system'][0]['text'] === 'You are a helpful assistant.'
                && count($payload['messages']) === 1
                && $payload['messages'][0]['role'] === 'user';
        });
    });

    it('transforms multimodal messages with text and image from file', function () {
        // Create a temporary test image file
        $tempImagePath = sys_get_temp_dir().'/test_image_'.uniqid().'.jpg';
        $imageData = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlbaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==');
        file_put_contents($tempImagePath, $imageData);

        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::text('What is in this image?'),
                    Content::image($tempImagePath),
                ]),
            ])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'A small test image']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $content = $payload['messages'][0]['content'];

            return is_array($content)
                && $content[0]['type'] === 'text'
                && $content[0]['text'] === 'What is in this image?'
                && $content[1]['type'] === 'image'
                && $content[1]['source']['type'] === 'base64'
                && isset($content[1]['source']['media_type'])
                && isset($content[1]['source']['data']);
        });

        expect($response->content)->toBe('A small test image');

        // Clean up
        @unlink($tempImagePath);
    });

    it('transforms multimodal messages with image URL', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::text('What is in this image?'),
                    Content::image('https://example.com/image.jpg'),
                ]),
            ])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'An image from a URL']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $content = $payload['messages'][0]['content'];

            return is_array($content)
                && $content[1]['type'] === 'image'
                && $content[1]['source']['type'] === 'url'
                && $content[1]['source']['url'] === 'https://example.com/image.jpg';
        });

        expect($response->content)->toBe('An image from a URL');
    });

    it('throws exception for audio content (not supported by Claude)', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::audio('/path/to/audio.mp3', ['format' => 'mp3']),
                ]),
            ])
            ->build();

        Http::fake();

        $this->loggerMock->shouldReceive('record')->never();
        $this->metricsMock->shouldReceive('record')->never();

        expect(fn () => $this->driver->send($request))
            ->toThrow(MessageValidationException::class);
    });
});

describe('ClaudeDriver - Tool Calling', function () {
    it('includes tools in request payload', function () {
        $request = Request::make()
            ->addMessage(Message::make('user', 'What is the weather?'))
            ->addTools(
                [
                    Tool::make(
                        name: 'get_weather',
                        description: 'Get the current weather',
                        properties: [
                            Property::string('location', 'City name', true),
                        ]
                    ),
                ])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_123',
                        'name' => 'get_weather',
                        'input' => ['location' => 'London'],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 30, 'output_tokens' => 15],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['tools'])
                && $payload['tools'][0]['name'] === 'get_weather'
                && $payload['tools'][0]['description'] === 'Get the current weather'
                && isset($payload['tools'][0]['input_schema']);
        });

        expect($response->toolCalls)->toHaveCount(1)
            ->and($response->toolCalls[0]->name)->toBe('get_weather')
            ->and($response->toolCalls[0]->arguments)->toBe(['location' => 'London'])
            ->and($response->toolCalls[0]->id)->toBe('toolu_123')
            ->and($response->finishReason)->toBe('tool_use');
    });

    it('handles multiple tool calls in response', function () {
        $request = Request::make()
            ->prompt('Check weather and traffic')
            ->addTools([
                Tool::make('get_weather', 'Get weather', [Property::string('location', 'City', true)]),
                Tool::make('get_traffic', 'Get traffic', [Property::string('location', 'City', true)]),
            ])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_123',
                        'name' => 'get_weather',
                        'input' => ['location' => 'London'],
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_456',
                        'name' => 'get_traffic',
                        'input' => ['location' => 'London'],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 40, 'output_tokens' => 20],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->toolCalls)->toHaveCount(2)
            ->and($response->toolCalls[0]->name)->toBe('get_weather')
            ->and($response->toolCalls[1]->name)->toBe('get_traffic');
    });

    it('handles mixed text and tool call content', function () {
        $request = Request::make()
            ->prompt('What is the weather?')
            ->addTools([Tool::make('get_weather', 'Get weather', [Property::string('location', 'City', true)])])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Let me check that for you.',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_123',
                        'name' => 'get_weather',
                        'input' => ['location' => 'London'],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 30, 'output_tokens' => 20],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->content)->toBe('Let me check that for you.')
            ->and($response->toolCalls)->toHaveCount(1)
            ->and($response->toolCalls[0]->name)->toBe('get_weather');
    });
});

describe('ClaudeDriver - Structured Output', function () {
    it('parses JSON response when response_format is set', function () {
        $request = Request::make()
            ->prompt('Generate a user profile')
            ->asJson()
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '{"name":"John","age":30}',
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->structuredOutput)->toBe(['name' => 'John', 'age' => 30])
            ->and($response->content)->toBe('{"name":"John","age":30}');
    });

    it('includes structured output with Schema object', function () {
        $schema = Schema::make(
            name: 'user_profile',
            properties: [
                Property::string('name', 'User name', true),
                Property::integer('age', 'User age', true),
                Property::string('email', 'User email', false),
            ],
            description: 'User profile schema'
        );

        $request = Request::make()
            ->prompt('Generate a user profile')
            ->asStructuredOutput($schema)
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '{"name":"Alice","age":25,"email":"alice@example.com"}',
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 15, 'output_tokens' => 10],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['output_format']['schema']) && $payload['output_format']['type'] === 'json_schema';
        });

        expect($response->structuredOutput)->toBe([
            'name' => 'Alice',
            'age' => 25,
            'email' => 'alice@example.com',
        ]);
    });
});

describe('ClaudeDriver - Request Parameters', function () {
    it('includes model in payload', function () {
        $request = Request::make()
            ->model('claude-3-5-haiku-20241022')
            ->prompt('Hello')
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-haiku-20241022',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $payload['model'] === 'claude-3-5-haiku-20241022';
        });
    });

    it('includes max_tokens in payload (required by Claude)', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->maxTokens(100)
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['max_tokens'])
                && $payload['max_tokens'] === 100;
        });
    });

    it('uses default max_tokens when not specified', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['max_tokens'])
                && $payload['max_tokens'] === 4096; // From driver config
        });
    });

    it('includes temperature in payload', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->temperature(0.8)
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['temperature'])
                && $payload['temperature'] === 0.8;
        });
    });

    it('merges additional options into payload', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->options(['top_p' => 0.9, 'top_k' => 40])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['top_p']) && $payload['top_p'] === 0.9
                && isset($payload['top_k']) && $payload['top_k'] === 40;
        });
    });

    it('uses raw payload when provided', function () {
        $rawPayload = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'user', 'content' => 'Raw request'],
            ],
            'custom_field' => 'custom_value',
        ];

        $request = Request::make()
            ->withRawPayload($rawPayload)
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) use ($rawPayload) {
            $payload = json_decode($request->body(), true);

            return $payload === $rawPayload;
        });
    });
});

describe('ClaudeDriver - Document Content', function () {
    it('transforms PDF document from file path to base64', function () {
        // Create a temporary test PDF file (minimal valid PDF)
        $tempPdfPath = sys_get_temp_dir().'/test_pdf_'.uniqid().'.pdf';
        $pdfData = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000056 00000 n\n0000000115 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n210\n%%EOF";
        file_put_contents($tempPdfPath, $pdfData);

        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::text('Analyze this document'),
                    Content::document($tempPdfPath),
                ]),
            ])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Document analyzed']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $content = $payload['messages'][0]['content'];

            return is_array($content)
                && $content[1]['type'] === 'document'
                && $content[1]['source']['type'] === 'base64'
                && $content[1]['source']['media_type'] === 'application/pdf'
                && isset($content[1]['source']['data']);
        });

        expect($response->content)->toBe('Document analyzed');

        // Clean up
        @unlink($tempPdfPath);
    });

    it('transforms document with URL', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::text('What are the key findings in this document?'),
                    Content::document('https://assets.anthropic.com/m/1cd9d098ac3e6467/original/Claude-3-Model-Card-October-Addendum.pdf'),
                ]),
            ])
            ->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Document contains key findings about Claude 3 models.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 150, 'output_tokens' => 20],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $content = $payload['messages'][0]['content'];

            return is_array($content)
                && $content[1]['type'] === 'document'
                && $content[1]['source']['type'] === 'url'
                && $content[1]['source']['url'] === 'https://assets.anthropic.com/m/1cd9d098ac3e6467/original/Claude-3-Model-Card-October-Addendum.pdf';
        });

        expect($response->content)->toBe('Document contains key findings about Claude 3 models.');
    });
});

describe('ClaudeDriver - Response Metadata', function () {
    it('includes response metadata', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_abc123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-3-5-sonnet-20241022',
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->metadata)->toHaveKey('id')
            ->and($response->metadata['id'])->toBe('msg_abc123')
            ->and($response->metadata)->toHaveKey('model')
            ->and($response->metadata['model'])->toBe('claude-3-5-sonnet-20241022');
    });
});

describe('ClaudeDriver - Transformation Methods', function () {
    it('transforms text content correctly', function () {
        $content = Content::text('Hello world');
        $result = invokeProtectedMethod($this->driver, 'transformTextContent', [$content]);

        expect($result)->toBe([
            'type' => 'text',
            'text' => 'Hello world',
        ]);
    });

    it('transforms tools correctly', function () {
        $tools = [
            Tool::make(
                name: 'get_weather',
                description: 'Get weather info',
                properties: [
                    Property::string('location', 'City name', true),
                    Property::string('unit', 'Temperature unit'),
                ]
            ),
        ];

        $result = invokeProtectedMethod($this->driver, 'transformTools', [$tools]);

        expect($result)->toBeArray()
            ->and($result[0])->toHaveKey('name')
            ->and($result[0]['name'])->toBe('get_weather')
            ->and($result[0])->toHaveKey('description')
            ->and($result[0]['description'])->toBe('Get weather info')
            ->and($result[0])->toHaveKey('input_schema');
    });

    it('parses tool calls correctly', function () {
        $toolCallData = [
            'id' => 'toolu_123',
            'type' => 'tool_use',
            'name' => 'get_weather',
            'input' => ['location' => 'London', 'unit' => 'celsius'],
        ];

        $result = invokeProtectedMethod($this->driver, 'parseToolCall', [$toolCallData]);

        expect($result)->toBeInstanceOf(ToolCall::class)
            ->and($result->id)->toBe('toolu_123')
            ->and($result->name)->toBe('get_weather')
            ->and($result->type)->toBe('tool_use')
            ->and($result->arguments)->toBe(['location' => 'London', 'unit' => 'celsius']);
    });

    it('normalizes file input from file path', function () {
        $tempFilePath = sys_get_temp_dir().'/test_file_'.uniqid().'.txt';
        file_put_contents($tempFilePath, 'test content');

        $result = invokeProtectedMethod($this->driver, 'normalizeFileInput', [$tempFilePath]);

        expect($result)->toBeString()
            ->and(base64_decode($result))->toBe('test content');

        @unlink($tempFilePath);
    });

    it('normalizes file input from base64', function () {
        $base64 = base64_encode('test content');
        $result = invokeProtectedMethod($this->driver, 'normalizeFileInput', [$base64]);

        expect($result)->toBe($base64);
    });

    it('throws exception for invalid file input', function () {
        expect(fn () => invokeProtectedMethod($this->driver, 'normalizeFileInput', ['invalid-data!@#']))
            ->toThrow(MessageValidationException::class);
    });
});
