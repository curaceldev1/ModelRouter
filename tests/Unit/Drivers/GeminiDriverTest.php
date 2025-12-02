<?php

use Curacel\LlmOrchestrator\DataObjects\Content;
use Curacel\LlmOrchestrator\DataObjects\Message;
use Curacel\LlmOrchestrator\DataObjects\Property;
use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Curacel\LlmOrchestrator\DataObjects\Schema;
use Curacel\LlmOrchestrator\DataObjects\Tool;
use Curacel\LlmOrchestrator\DataObjects\ToolCall;
use Curacel\LlmOrchestrator\Drivers\GeminiDriver;
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

    // Create Gemini driver instance
    $this->driver = new GeminiDriver('gemini-test', [
        'api_key' => 'test-api-key',
        'base_url' => 'https://generativelanguage.googleapis.com',
        'model' => 'gemini-1.5-pro',
        'timeout' => 30,
        'max_retries' => 3,
    ]);
});

describe('GeminiDriver - Basic Configuration', function () {
    it('returns the correct driver name', function () {
        $name = invokeProtectedMethod($this->driver, 'getName');
        expect($name)->toBe('gemini');
    });

    it('returns the correct base URL', function () {
        $baseUrl = invokeProtectedMethod($this->driver, 'getBaseUrl');
        expect($baseUrl)->toBe('https://generativelanguage.googleapis.com');
    });

    it('trims trailing slash from base URL', function () {
        $driver = new GeminiDriver('gemini-test', [
            'api_key' => 'test-key',
            'base_url' => 'https://generativelanguage.googleapis.com/',
        ]);

        $baseUrl = invokeProtectedMethod($driver, 'getBaseUrl');
        expect($baseUrl)->toBe('https://generativelanguage.googleapis.com');
    });

    it('generates correct request headers', function () {
        $headers = invokeProtectedMethod($this->driver, 'getRequestHeaders');

        expect($headers)->toHaveKey('x-goog-api-key')
            ->and($headers['x-goog-api-key'])->toBe('test-api-key')
            ->and($headers['Content-Type'])->toBe('application/json')
            ->and($headers['Accept'])->toBe('application/json');
    });
});

describe('GeminiDriver - Request Execution', function () {
    it('successfully executes a simple request', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Hi there!'],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 5,
                    'totalTokenCount' => 15,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->content)->toBe('Hi there!')
            ->and($response->driver)->toBe('gemini')
            ->and($response->model)->toBe('gemini-1.5-pro')
            ->and($response->inputTokens)->toBe(10)
            ->and($response->outputTokens)->toBe(5)
            ->and($response->totalTokens)->toBe(15)
            ->and($response->finishReason)->toBe('STOP');
    });

    it('throws RequestFailedException on HTTP error', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'message' => 'Invalid API key',
                    'code' => 401,
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
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Server error', 'code' => 500]], 500)
                ->push(['error' => ['message' => 'Server error', 'code' => 500]], 500)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [['text' => 'Success']],
                                'role' => 'model',
                            ],
                            'finishReason' => 'STOP',
                        ],
                    ],
                    'usageMetadata' => [
                        'promptTokenCount' => 10,
                        'candidatesTokenCount' => 5,
                        'totalTokenCount' => 15,
                    ],
                ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->content)->toBe('Success');
    });
});

describe('GeminiDriver - Message Transformation', function () {
    it('transforms simple text messages correctly', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', 'Hello'),
                Message::make('assistant', 'Hi'),
                Message::make('user', 'How are you?'),
            ])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Response']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 20,
                    'candidatesTokenCount' => 5,
                    'totalTokenCount' => 25,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['contents'])
                && $payload['contents'][0]['role'] === 'user'
                && $payload['contents'][0]['parts'][0]['text'] === 'Hello'
                && $payload['contents'][1]['role'] === 'model'
                && $payload['contents'][1]['parts'][0]['text'] === 'Hi'
                && $payload['contents'][2]['role'] === 'user'
                && $payload['contents'][2]['parts'][0]['text'] === 'How are you?';
        });
    });

    it('extracts system messages to systemInstruction parameter', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('system', 'You are a helpful assistant.'),
                Message::make('user', 'Hello'),
            ])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Hi']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 15,
                    'candidatesTokenCount' => 3,
                    'totalTokenCount' => 18,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['systemInstruction'])
                && isset($payload['systemInstruction']['parts'])
                && $payload['systemInstruction']['parts'][0]['text'] === 'You are a helpful assistant.'
                && count($payload['contents']) === 1
                && $payload['contents'][0]['role'] === 'user';
        });
    });

    it('transforms multimodal messages with text and image from base64', function () {
        $validBase64 = 'SGVsbG8gV29ybGQ='; // "Hello World" in base64

        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::text('What is in this image?'),
                    Content::image('data:image/jpeg;base64,'.$validBase64),
                ]),
            ])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'An image']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 50,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 60,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) use ($validBase64) {
            $payload = json_decode($request->body(), true);
            $parts = $payload['contents'][0]['parts'];

            return isset($parts[0]['text'])
                && $parts[0]['text'] === 'What is in this image?'
                && isset($parts[1]['inlineData'])
                && $parts[1]['inlineData']['mimeType'] === 'image/jpeg'
                && $parts[1]['inlineData']['data'] === $validBase64;
        });

        expect($response->content)->toBe('An image');
    });

    it('transforms multimodal messages with image URL when allow_url is true', function () {
        // Create a temporary image file for testing
        $tempImagePath = sys_get_temp_dir().'/test_image_'.uniqid().'.jpg';
        $imageData = base64_decode('SGVsbG8gV29ybGQ=');
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
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'An image from file']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 50,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 60,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $parts = $payload['contents'][0]['parts'];

            return isset($parts[1]['inlineData'])
                && isset($parts[1]['inlineData']['data']);
        });

        expect($response->content)->toBe('An image from file');

        // Clean up
        @unlink($tempImagePath);
    });

    it('throws exception for image URL when allow_url is not set', function () {
        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::image('https://example.com/image.jpg'),
                ]),
            ])
            ->build();

        Http::fake();

        expect(fn () => invokeProtectedMethod($this->driver, 'execute', [$request]))
            ->toThrow(MessageValidationException::class);
    });
});

describe('GeminiDriver - Tool Calling', function () {
    it('includes tools in request payload', function () {
        $request = Request::make()
            ->addMessage(Message::make('user', 'What is the weather?'))
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
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'functionCall' => [
                                        'name' => 'get_weather',
                                        'args' => ['location' => 'London'],
                                    ],
                                ],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 30,
                    'candidatesTokenCount' => 15,
                    'totalTokenCount' => 45,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['tools'])
                && isset($payload['tools'][0]['functionDeclarations'])
                && $payload['tools'][0]['functionDeclarations'][0]['name'] === 'get_weather'
                && $payload['tools'][0]['functionDeclarations'][0]['description'] === 'Get the current weather';
        });

        expect($response->toolCalls)->toHaveCount(1)
            ->and($response->toolCalls[0]->name)->toBe('get_weather')
            ->and($response->toolCalls[0]->arguments)->toBe(['location' => 'London']);
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
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'functionCall' => [
                                        'name' => 'get_weather',
                                        'args' => ['location' => 'London'],
                                    ],
                                ],
                                [
                                    'functionCall' => [
                                        'name' => 'get_traffic',
                                        'args' => ['location' => 'London'],
                                    ],
                                ],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 40,
                    'candidatesTokenCount' => 20,
                    'totalTokenCount' => 60,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->toolCalls)->toHaveCount(2)
            ->and($response->toolCalls[0]->name)->toBe('get_weather')
            ->and($response->toolCalls[1]->name)->toBe('get_traffic');
    });

    it('handles mixed text and function call content', function () {
        $request = Request::make()
            ->prompt('What is the weather?')
            ->addTools([Tool::make('get_weather', 'Get weather', [Property::string('location', 'City', true)])])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => 'Let me check that for you.',
                                ],
                                [
                                    'functionCall' => [
                                        'name' => 'get_weather',
                                        'args' => ['location' => 'London'],
                                    ],
                                ],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 30,
                    'candidatesTokenCount' => 20,
                    'totalTokenCount' => 50,
                ],
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

describe('GeminiDriver - Structured Output', function () {
    it('parses JSON response when response_format is set', function () {
        $request = Request::make()
            ->prompt('Generate a user profile')
            ->asJson()
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"name":"John","age":30}'],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 5,
                    'totalTokenCount' => 15,
                ],
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
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"name":"Alice","age":25,"email":"alice@example.com"}'],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 15,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 25,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['generationConfig']['responseSchema'])
                && isset($payload['generationConfig']['responseMimeType'])
                && $payload['generationConfig']['responseMimeType'] === 'application/json';
        });

        expect($response->structuredOutput)->toBe([
            'name' => 'Alice',
            'age' => 25,
            'email' => 'alice@example.com',
        ]);
    });
});

describe('GeminiDriver - Request Parameters', function () {
    it('includes model in the request URL', function () {
        $request = Request::make()
            ->model('gemini-1.5-flash')
            ->prompt('Hello')
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Hi']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 5,
                    'candidatesTokenCount' => 2,
                    'totalTokenCount' => 7,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'models/gemini-1.5-flash:generateContent');
        });
    });

    it('includes maxOutputTokens in generationConfig', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->maxTokens(100)
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Hi']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 5,
                    'candidatesTokenCount' => 2,
                    'totalTokenCount' => 7,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['generationConfig']['maxOutputTokens'])
                && $payload['generationConfig']['maxOutputTokens'] === 100;
        });
    });

    it('includes temperature in generationConfig', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->temperature(0.8)
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Hi']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 5,
                    'candidatesTokenCount' => 2,
                    'totalTokenCount' => 7,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['generationConfig']['temperature'])
                && $payload['generationConfig']['temperature'] === 0.8;
        });
    });

    it('merges additional options into payload', function () {
        $request = Request::make()
            ->prompt('Hello')
            ->options(['topP' => 0.9, 'topK' => 40])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Hi']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 5,
                    'candidatesTokenCount' => 2,
                    'totalTokenCount' => 7,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return isset($payload['topP']) && $payload['topP'] === 0.9
                && isset($payload['topK']) && $payload['topK'] === 40;
        });
    });

    it('uses raw payload when provided', function () {
        $rawPayload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => 'Raw request']],
                ],
            ],
            'custom_field' => 'custom_value',
        ];

        $request = Request::make()
            ->withRawPayload($rawPayload)
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Response']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 5,
                    'candidatesTokenCount' => 2,
                    'totalTokenCount' => 7,
                ],
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

describe('GeminiDriver - Audio Content', function () {
    it('transforms audio content with base64 data', function () {
        $validBase64 = 'SGVsbG8gV29ybGQ='; // "Hello World" in base64

        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::audio($validBase64, ['mime_type' => 'audio/wav']),
                ]),
            ])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Transcription']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 50,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 60,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) use ($validBase64) {
            $payload = json_decode($request->body(), true);
            $parts = $payload['contents'][0]['parts'];

            return isset($parts[0]['inlineData'])
                && $parts[0]['inlineData']['mimeType'] === 'audio/wav'
                && $parts[0]['inlineData']['data'] === $validBase64;
        });

        expect($response->content)->toBe('Transcription');
    });

    it('uses default mime type for audio when not specified', function () {
        $validBase64 = 'SGVsbG8gV29ybGQ=';

        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::audio($validBase64),
                ]),
            ])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Transcription']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 50,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 60,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $this->driver->send($request);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);
            $parts = $payload['contents'][0]['parts'];

            return $parts[0]['inlineData']['mimeType'] === 'audio/wav';
        });
    });
});

describe('GeminiDriver - File Content', function () {
    it('transforms file content with base64 data', function () {
        $validBase64 = 'SGVsbG8gV29ybGQ=';

        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::file($validBase64, ['mime_type' => 'application/pdf']),
                ]),
            ])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'File processed']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 50,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 60,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) use ($validBase64) {
            $payload = json_decode($request->body(), true);
            $parts = $payload['contents'][0]['parts'];

            return isset($parts[0]['inlineData'])
                && $parts[0]['inlineData']['mimeType'] === 'application/pdf'
                && $parts[0]['inlineData']['data'] === $validBase64;
        });

        expect($response->content)->toBe('File processed');
    });

    it('transforms document content with base64 data', function () {
        $validBase64 = 'SGVsbG8gV29ybGQ=';

        $request = Request::make()
            ->addMessages([
                Message::make('user', [
                    Content::document($validBase64, ['mime_type' => 'application/pdf']),
                ]),
            ])
            ->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Document processed']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 50,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 60,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        Http::assertSent(function ($request) use ($validBase64) {
            $payload = json_decode($request->body(), true);
            $parts = $payload['contents'][0]['parts'];

            return isset($parts[0]['inlineData'])
                && $parts[0]['inlineData']['mimeType'] === 'application/pdf'
                && $parts[0]['inlineData']['data'] === $validBase64;
        });

        expect($response->content)->toBe('Document processed');
    });
});

describe('GeminiDriver - Response Metadata', function () {
    it('includes response metadata', function () {
        $request = Request::make()->prompt('Hello')->build();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'Hi']],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 5,
                    'candidatesTokenCount' => 2,
                    'totalTokenCount' => 7,
                ],
            ]),
        ]);

        $this->loggerMock->shouldReceive('record')->once();
        $this->metricsMock->shouldReceive('record')->once();

        $response = $this->driver->send($request);

        expect($response->metadata)->toHaveKey('model')
            ->and($response->metadata['model'])->toBe('gemini-1.5-pro');
    });
});

describe('GeminiDriver - Transformation Methods', function () {
    it('transforms text content correctly', function () {
        $content = Content::text('Hello world');
        $result = invokeProtectedMethod($this->driver, 'transformTextContent', [$content]);

        expect($result)->toBe([
            'text' => 'Hello world',
        ]);
    });

    it('transforms image content correctly', function () {
        $validBase64 = 'SGVsbG8gV29ybGQ=';
        $content = Content::image('data:image/jpeg;base64,'.$validBase64);
        $result = invokeProtectedMethod($this->driver, 'transformImageContent', [$content]);

        expect($result)->toHaveKey('inlineData')
            ->and($result['inlineData']['mimeType'])->toBe('image/jpeg')
            ->and($result['inlineData']['data'])->toBe($validBase64);
    });

    it('transforms audio content correctly', function () {
        $validBase64 = 'SGVsbG8gV29ybGQ=';
        $content = Content::audio($validBase64, ['mime_type' => 'audio/mp3']);
        $result = invokeProtectedMethod($this->driver, 'transformAudioContent', [$content]);

        expect($result)->toHaveKey('inlineData')
            ->and($result['inlineData']['mimeType'])->toBe('audio/mp3')
            ->and($result['inlineData']['data'])->toBe($validBase64);
    });

    it('transforms file content correctly', function () {
        $validBase64 = 'SGVsbG8gV29ybGQ=';
        $content = Content::file($validBase64, ['mime_type' => 'application/pdf']);
        $result = invokeProtectedMethod($this->driver, 'transformFileContent', [$content]);

        expect($result)->toHaveKey('inlineData')
            ->and($result['inlineData']['mimeType'])->toBe('application/pdf')
            ->and($result['inlineData']['data'])->toBe($validBase64);
    });

    it('transforms tools correctly', function () {
        $tools = [
            Tool::make(
                name: 'get_weather',
                description: 'Get weather info',
                properties: [
                    Property::string('location', 'City name', true),
                    Property::string('unit', 'Temperature unit', false),
                ]
            ),
        ];

        $result = invokeProtectedMethod($this->driver, 'transformTools', [$tools]);

        expect($result)->toBeArray()
            ->and($result[0])->toHaveKey('name')
            ->and($result[0]['name'])->toBe('get_weather')
            ->and($result[0])->toHaveKey('description')
            ->and($result[0]['description'])->toBe('Get weather info')
            ->and($result[0])->toHaveKey('parameters');
    });

    it('parses function calls correctly', function () {
        $functionCallData = [
            'name' => 'get_weather',
            'args' => ['location' => 'London', 'unit' => 'celsius'],
        ];

        $result = invokeProtectedMethod($this->driver, 'parseFunctionCall', [$functionCallData]);

        expect($result)->toBeInstanceOf(ToolCall::class)
            ->and($result->id)->toBe('get_weather')
            ->and($result->name)->toBe('get_weather')
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

    it('transforms message with string content', function () {
        $message = Message::make('user', 'Hello');
        $transformed = invokeProtectedMethod($this->driver, 'transformMessage', [$message]);

        expect($transformed)->toBe([
            'role' => 'user',
            'parts' => [['text' => 'Hello']],
        ]);
    });

    it('transforms message with multimodal content', function () {
        $message = Message::make('user', [
            Content::text('Check this'),
            Content::image('data:image/jpeg;base64,SGVsbG8gV29ybGQ='),
        ]);

        $transformed = invokeProtectedMethod($this->driver, 'transformMessage', [$message]);

        expect($transformed['role'])->toBe('user')
            ->and($transformed['parts'])->toBeArray()
            ->and($transformed['parts'])->toHaveCount(2)
            ->and($transformed['parts'][0]['text'])->toBe('Check this')
            ->and($transformed['parts'][1])->toHaveKey('inlineData');
    });

    it('transforms assistant role to model role', function () {
        $message = Message::make('assistant', 'Hello');
        $transformed = invokeProtectedMethod($this->driver, 'transformMessage', [$message]);

        expect($transformed['role'])->toBe('model');
    });

    it('transforms response correctly', function () {
        $apiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Test response'],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 20,
                'totalTokenCount' => 30,
            ],
        ];

        $request = Request::make()->prompt('Test')->build();
        $response = invokeProtectedMethod($this->driver, 'transformResponse', [$apiResponse, $request]);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->content)->toBe('Test response')
            ->and($response->driver)->toBe('gemini')
            ->and($response->model)->toBe('gemini-1.5-pro')
            ->and($response->inputTokens)->toBe(10)
            ->and($response->outputTokens)->toBe(20)
            ->and($response->totalTokens)->toBe(30)
            ->and($response->finishReason)->toBe('STOP');
    });

    it('builds request payload correctly', function () {
        $request = Request::make()
            ->model('gemini-1.5-pro')
            ->prompt('Hello')
            ->maxTokens(100)
            ->temperature(0.7)
            ->build();

        $payload = invokeProtectedMethod($this->driver, 'buildRequestPayload', [$request]);

        expect($payload)->toHaveKeys(['contents', 'generationConfig'])
            ->and($payload['generationConfig']['maxOutputTokens'])->toBe(100)
            ->and($payload['generationConfig']['temperature'])->toBe(0.7)
            ->and($payload['contents'])->toHaveCount(1);
    });

    it('uses raw payload when provided in buildRequestPayload', function () {
        $rawPayload = ['custom' => 'payload', 'contents' => []];
        $request = Request::make()->withRawPayload($rawPayload)->build();

        $payload = invokeProtectedMethod($this->driver, 'buildRequestPayload', [$request]);

        expect($payload)->toBe($rawPayload);
    });
});
