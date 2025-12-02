<?php

use Curacel\LlmOrchestrator\DataObjects\Content;
use Curacel\LlmOrchestrator\DataObjects\Message;
use Curacel\LlmOrchestrator\DataObjects\Property;
use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Curacel\LlmOrchestrator\DataObjects\Schema;
use Curacel\LlmOrchestrator\DataObjects\Tool;
use Curacel\LlmOrchestrator\Drivers\OpenAiDriver;
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

    // Create OpenAI driver instance
    $this->driver = new OpenAiDriver('openai-test', [
        'api_key' => 'test-api-key',
        'base_url' => 'https://api.openai.com',
        'model' => 'gpt-4',
        'timeout' => 30,
        'max_retries' => 3,
    ]);
});

describe('OpenAiDriver - Basic Configuration', function () {
    it('returns the correct driver name', function () {
        $name = invokeProtectedMethod($this->driver, 'getName');
        expect($name)->toBe('openai');
    });

    it('returns the correct base URL', function () {
        $baseUrl = invokeProtectedMethod($this->driver, 'getBaseUrl');
        expect($baseUrl)->toBe('https://api.openai.com');
    });

    it('trims trailing slash from base URL', function () {
        $driver = new OpenAiDriver('openai-test', [
            'api_key' => 'test-key',
            'base_url' => 'https://api.openai.com/',
        ]);

        $baseUrl = invokeProtectedMethod($driver, 'getBaseUrl');

        expect($baseUrl)->toBe('https://api.openai.com');
    });

    it('generates correct request headers', function () {
        $headers = invokeProtectedMethod($this->driver, 'getRequestHeaders');

        expect($headers)->toHaveKey('Authorization')
            ->and($headers['Authorization'])->toBe('Bearer test-api-key')
            ->and($headers['Content-Type'])->toBe('application/json')
            ->and($headers['Accept'])->toBe('application/json');
    });
});

describe('OpenAiDriver - Request Execution', function () {
    it('successfully executes a simple request', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Hi there!',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                    'total_tokens' => 15,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->content)->toBe('Hi there!')
            ->and($response->driver)->toBe('openai')
            ->and($response->model)->toBe('gpt-4')
            ->and($response->inputTokens)->toBe(10)
            ->and($response->outputTokens)->toBe(5)
            ->and($response->totalTokens)->toBe(15)
            ->and($response->finishReason)->toBe('stop');
    });

    it('throws RequestFailedException on HTTP error', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'Invalid API key',
                    'type' => 'invalid_request_error',
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
            'api.openai.com/*' => Http::sequence()
                ->push(['error' => 'Server error'], 500)
                ->push(['error' => 'Server error'], 500)
                ->push([
                    'id' => 'chatcmpl-123',
                    'model' => 'gpt-4',
                    'choices' => [
                        ['message' => ['content' => 'Success'], 'finish_reason' => 'stop'],
                    ],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->content)->toBe('Success');
    });
});

describe('OpenAiDriver - Message Transformation', function () {
    it('transforms simple text messages correctly', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', 'Hello'),
                Message::make('assistant', 'Hi'),
            ])
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => 'Response'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
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
                && $payload['messages'][1]['content'] === 'Hi';
        });
    });

    it('transforms multimodal messages with text and image', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::text('What is in this image?'),
                    Content::image('https://example.com/image.jpg'),
                ]),
            ])
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4-vision',
                'choices' => [['message' => ['content' => 'A cat'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5, 'total_tokens' => 55],
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
                && $content[1]['type'] === 'image_url'
                && $content[1]['image_url']['url'] === 'https://example.com/image.jpg';
        });

        expect($response->content)->toBe('A cat');
    });
});

describe('OpenAiDriver - Tool Calling', function () {
    it('includes tools in request payload', function () {
        $request = Request::make()
            ->prompt('What is the weather?')
            ->addTools([
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
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'message' => [
                            'content' => null,
                            'tool_calls' => [
                                [
                                    'id' => 'call_123',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'get_weather',
                                        'arguments' => '{"location":"London"}',
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => 'tool_calls',
                    ],
                ],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['tools'])
                && $payload['tools'][0]['type'] === 'function'
                && $payload['tools'][0]['function']['name'] === 'get_weather';
        });

        expect($response->toolCalls)->toHaveCount(1)
            ->and($response->toolCalls[0]->name)->toBe('get_weather')
            ->and($response->toolCalls[0]->arguments)->toBe(['location' => 'London'])
            ->and($response->finishReason)->toBe('tool_calls');
    });
});

describe('OpenAiDriver - Structured Output', function () {
    it('parses JSON response when response_format is set', function () {
        $request = Request::make()
            ->prompt('Generate a user profile')
            ->asJson()
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"name":"John","age":30}',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->structuredOutput)->toBe(['name' => 'John', 'age' => 30])
            ->and($response->content)->toBe('{"name":"John","age":30}');
    });

    it('includes response_format in payload', function () {
        $request = Request::make()
            ->prompt('Generate JSON')
            ->asJson()
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => '{}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['response_format'])
                && $payload['response_format']['type'] === 'json_object';
        });
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
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'message' => ['content' => '{"name":"Alice","age":25,"email":"alice@example.com"}'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 10, 'total_tokens' => 25],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['response_format']['json_schema']['schema']) && $payload['response_format']['type'] === 'json_schema' && $payload['response_format']['json_schema']['name'] === 'user_profile';
        });

        expect($response->structuredOutput)->toBe([
            'name' => 'Alice',
            'age' => 25,
            'email' => 'alice@example.com',
        ]);
    });
});

describe('OpenAiDriver - Request Parameters', function () {
    it('includes model in payload', function () {
        $request = Request::make()
            ->model('gpt-4-mini')
            ->prompt('Hello')
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4-mini',
                'choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $payload['model'] === 'gpt-4-mini';
        });
    });

    it('includes max_tokens as max_completion_tokens in payload', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->maxTokens(100)
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['max_completion_tokens'])
                && $payload['max_completion_tokens'] === 100;
        });
    });

    it('includes temperature in payload', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->temperature(0.8)
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
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
            ->options(['top_p' => 0.9, 'frequency_penalty' => 0.5])
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['top_p']) && $payload['top_p'] === 0.9
                && isset($payload['frequency_penalty']) && $payload['frequency_penalty'] === 0.5;
        });
    });

    it('uses raw payload when provided', function () {
        $rawPayload = [
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'user', 'content' => 'Raw request'],
            ],
            'custom_field' => 'custom_value',
        ];

        $request = Request::make()
            ->withRawPayload($rawPayload)
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => 'Response'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
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

describe('OpenAiDriver - Audio Content', function () {
    it('throws exception when audio format is not specified', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::audio('base64-audio-data'),
                ]),
            ])
            ->build();

        Http::fake();

        expect(fn () => invokeProtectedMethod($this->driver, 'execute', [$request]))
            ->toThrow(MessageValidationException::class);
    });

    it('transforms audio content with format correctly', function () {
        // Use valid base64 encoded data (this is "Hello World" encoded)
        $validBase64 = 'SGVsbG8gV29ybGQ=';

        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::audio($validBase64, ['format' => 'wav']),
                ]),
            ])
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => 'Transcription'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $content = $payload['messages'][0]['content'];

            return isset($content[0]['type'])
                && $content[0]['type'] === 'input_audio'
                && isset($content[0]['input_audio']['format'])
                && $content[0]['input_audio']['format'] === 'wav';
        });
    });
});

describe('OpenAiDriver - File Content', function () {
    it('transforms file content with file_id', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::file('file-data', ['file_id' => 'file-123']),
                ]),
            ])
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => 'File processed'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $content = $payload['messages'][0]['content'];

            return isset($content[0]['type'])
                && $content[0]['type'] === 'file'
                && isset($content[0]['file']['file_id'])
                && $content[0]['file']['file_id'] === 'file-123';
        });
    });

    it('transforms file content with base64 data', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::file('SGVsbG8gV29ybGQ=', ['filename' => 'test.txt']),
                ]),
            ])
            ->build();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'chatcmpl-123',
                'model' => 'gpt-4',
                'choices' => [['message' => ['content' => 'File processed'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $content = $payload['messages'][0]['content'];

            return isset($content[0]['type'])
                && $content[0]['type'] === 'file'
                && isset($content[0]['file']['file_data'])
                && $content[0]['file']['filename'] === 'test.txt';
        });
    });
});

describe('OpenAiDriver - Transformation Methods', function () {
    it('builds request payload correctly', function () {
        $request = Request::make()
            ->model('gpt-4')
            ->prompt('Hello')
            ->maxTokens(100)
            ->temperature(0.7)
            ->build();

        $payload = invokeProtectedMethod($this->driver, 'buildRequestPayload', [$request]);

        expect($payload)->toHaveKeys(['model', 'messages', 'max_completion_tokens', 'temperature'])
            ->and($payload['model'])->toBe('gpt-4')
            ->and($payload['max_completion_tokens'])->toBe(100)
            ->and($payload['temperature'])->toBe(0.7)
            ->and($payload['messages'])->toHaveCount(1);
    });

    it('uses raw payload when provided in buildRequestPayload', function () {
        $rawPayload = ['custom' => 'payload', 'model' => 'test'];
        $request = Request::make()->withRawPayload($rawPayload)->build();

        $payload = invokeProtectedMethod($this->driver, 'buildRequestPayload', [$request]);

        expect($payload)->toBe($rawPayload);
    });

    it('transforms text content correctly', function () {
        $content = Content::text('Hello world');
        $transformed = invokeProtectedMethod($this->driver, 'transformContentPart', [$content]);

        expect($transformed)->toBe([
            'type' => 'text',
            'text' => 'Hello world',
        ]);
    });

    it('transforms image content with detail metadata', function () {
        $content = Content::image('https://example.com/image.jpg', ['detail' => 'high']);
        $transformed = invokeProtectedMethod($this->driver, 'transformContentPart', [$content]);

        expect($transformed)->toHaveKeys(['type', 'image_url'])
            ->and($transformed['type'])->toBe('image_url')
            ->and($transformed['image_url']['url'])->toBe('https://example.com/image.jpg')
            ->and($transformed['image_url']['detail'])->toBe('high');
    });

    it('uses auto detail by default for images', function () {
        $content = Content::image('https://example.com/image.jpg');
        $transformed = invokeProtectedMethod($this->driver, 'transformContentPart', [$content]);

        expect($transformed['image_url']['detail'])->toBe('auto');
    });

    it('transforms tools correctly', function () {
        $tools = [
            Tool::make('test_tool', 'A test tool', [
                Property::string('param1', 'First parameter', true),
            ]),
        ];

        $transformed = invokeProtectedMethod($this->driver, 'transformTools', [$tools]);

        expect($transformed)->toHaveCount(1)
            ->and($transformed[0])->toHaveKeys(['type', 'function'])
            ->and($transformed[0]['type'])->toBe('function')
            ->and($transformed[0]['function']['name'])->toBe('test_tool')
            ->and($transformed[0]['function']['description'])->toBe('A test tool');
    });

    it('parses tool calls correctly', function () {
        $toolCallData = [
            'id' => 'call_abc123',
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'arguments' => '{"city":"London","unit":"celsius"}',
            ],
        ];

        $toolCall = invokeProtectedMethod($this->driver, 'parseToolCall', [$toolCallData]);

        expect($toolCall->id)->toBe('call_abc123')
            ->and($toolCall->name)->toBe('get_weather')
            ->and($toolCall->type)->toBe('function')
            ->and($toolCall->arguments)->toBe(['city' => 'London', 'unit' => 'celsius']);
    });

    it('transforms response correctly', function () {
        $apiResponse = [
            'id' => 'chatcmpl-123',
            'model' => 'gpt-4',
            'choices' => [
                [
                    'message' => [
                        'content' => 'Test response',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ];

        $request = Request::make()->prompt('Test')->build();
        $response = invokeProtectedMethod($this->driver, 'transformResponse', [$apiResponse, $request]);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->content)->toBe('Test response')
            ->and($response->driver)->toBe('openai')
            ->and($response->model)->toBe('gpt-4')
            ->and($response->inputTokens)->toBe(10)
            ->and($response->outputTokens)->toBe(20)
            ->and($response->totalTokens)->toBe(30)
            ->and($response->finishReason)->toBe('stop');
    });

    it('transforms message with string content', function () {
        $message = Message::make('user', 'Hello');
        $transformed = invokeProtectedMethod($this->driver, 'transformMessage', [$message]);

        expect($transformed)->toBe([
            'role' => 'user',
            'content' => 'Hello',
        ]);
    });

    it('transforms message with multimodal content', function () {
        $message = Message::make('user', [
            Content::text('Check this'),
            Content::image('https://example.com/img.jpg'),
        ]);

        $transformed = invokeProtectedMethod($this->driver, 'transformMessage', [$message]);

        expect($transformed['role'])->toBe('user')
            ->and($transformed['content'])->toBeArray()
            ->and($transformed['content'])->toHaveCount(2)
            ->and($transformed['content'][0]['type'])->toBe('text')
            ->and($transformed['content'][1]['type'])->toBe('image_url');
    });
});
