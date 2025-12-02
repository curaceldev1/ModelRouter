# Laravel LLM Orchestrator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/curacel/laravel-llm-orchestrator.svg?style=flat-square)](https://packagist.org/packages/curacel/laravel-llm-orchestrator)
[![Total Downloads](https://img.shields.io/packagist/dt/curacel/laravel-llm-orchestrator.svg?style=flat-square)](https://packagist.org/packages/curacel/laravel-llm-orchestrator)
[![License](https://img.shields.io/packagist/l/curacel/laravel-llm-orchestrator.svg?style=flat-square)](https://packagist.org/packages/curacel/laravel-llm-orchestrator)

A vendor-agnostic LLM execution layer for Laravel applications that provides a unified interface for interacting with multiple Large Language Model providers. Includes built-in drivers for OpenAI, Anthropic Claude, and Google Gemini, with support for custom provider implementations.

## Table of Contents

- [Laravel LLM Orchestrator](#laravel-llm-orchestrator)
  - [Table of Contents](#table-of-contents)
  - [Features](#features)
  - [Requirements](#requirements)
  - [Supported Providers](#supported-providers)
  - [Core Concepts](#core-concepts)
    - [Clients vs Drivers](#clients-vs-drivers)
  - [Installation](#installation)
  - [Quick Start](#quick-start)
    - [Configure Your Providers](#configure-your-providers)
  - [Basic Usage](#basic-usage)
    - [Simple Prompts](#simple-prompts)
    - [Multi-turn Conversations](#multi-turn-conversations)
    - [Multimedia Content](#multimedia-content)
    - [Complex Requests](#complex-requests)
  - [Advanced Features](#advanced-features)
    - [Process Mapping](#process-mapping)
    - [Function Calling \& Tools](#function-calling--tools)
    - [Structured Output](#structured-output)
    - [Automatic Fallbacks](#automatic-fallbacks)
    - [Raw Provider Access](#raw-provider-access)
  - [Analytics \& Monitoring](#analytics--monitoring)
    - [Execution Logging](#execution-logging)
    - [Daily Metrics](#daily-metrics)
  - [Error Handling](#error-handling)
  - [Custom Drivers](#custom-drivers)
  - [Configuration](#configuration)
  - [Testing](#testing)
  - [Contributing](#contributing)
  - [Security](#security)
  - [License](#license)
  - [Credits](#credits)

## Features

- 🚀 **Unified API** - Single interface for multiple LLM providers
- 🔄 **Automatic Fallbacks** - Seamless failover between providers
- 📊 **Built-in Analytics** - Request logging, metrics, and cost tracking
- ⚙️ **Process Mapping** - Route specific processes to optimal models
- 🛠 **Tool Support** - Function calling and structured outputs
- 🔧 **Extensible** - Easy to add custom drivers and providers

## Requirements

- PHP 8.2+
- Laravel 9.28+
- Laravel Nova 4.0+ (optional, for admin interface)

## Supported Providers

| Provider | Built-in Driver | Chat | Images | Audio | Documents | Function Calling | Structured Output |
|----------|-----------------|------|--------|-------|-----------|------------------|-------------------|
| **OpenAI** | `openai` | ✅ | ✅ | ✅ | ✅ (as files) | ✅ | ✅ |
| **Anthropic Claude** | `claude` | ✅ | ✅ | ❌ | ✅ (PDF only) | ✅ | ✅ |
| **Google Gemini** | `gemini` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Custom** | `custom` | *Depends on implementation* | *Depends on implementation* | *Depends on implementation* | *Depends on implementation* | *Depends on implementation* | *Depends on implementation* |

## Core Concepts

### Clients vs Drivers

Understanding the distinction between **clients** and **drivers** is key to using this package effectively:

- **Driver**: The implementation that handles communication with a specific LLM provider. The package includes built-in drivers for OpenAI, Claude, and Gemini.
- **Client**: A named configuration that uses a driver to connect to a provider with specific settings

**Example**: You can have multiple OpenAI clients with different configurations:

```php
'clients' => [
    'openai-fast' => [
        'driver' => 'openai',  // Uses the built-in OpenAI driver
        'api_key' => env('OPENAI_API_KEY'),
        'model' => 'gpt-4o-mini',
        'max_tokens' => 500,
        'temperature' => 0.3,
    ],
    'openai-creative' => [
        'driver' => 'openai',  // Same driver, different config
        'api_key' => env('OPENAI_API_KEY'), 
        'model' => 'gpt-4o',
        'max_tokens' => 2000,
        'temperature' => 0.9,
    ],
    'openai-analysis' => [
        'driver' => 'openai',  // Another OpenAI client for analysis
        'api_key' => env('OPENAI_API_KEY'),
        'model' => 'gpt-4o',
        'max_tokens' => 4000,
        'temperature' => 0.1,
        'timeout' => 120,
    ],
    'custom-llm' => [
        'driver' => 'custom',  // Uses your custom driver implementation
        'via' => \App\Drivers\CustomLlmDriver::class,
        'api_key' => env('CUSTOM_API_KEY'),
    ],
],

// Usage examples
$fastResponse = Llm::using('openai-fast')->prompt('Quick answer needed');
$creativeResponse = Llm::using('openai-creative')->prompt('Write a creative story');
$analysisResponse = Llm::using('openai-analysis')->prompt('Analyze this complex data');
```

This allows you to:
- Use different models from the same provider
- Have different configurations for different use cases
- Switch between providers easily
- Create custom integrations while maintaining a consistent interface

## Installation

Install via Composer:

```bash
composer require curacel/laravel-llm-orchestrator
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Curacel\LlmOrchestrator\LlmOrchestratorServiceProvider" --tag="config"
```

**Optional**: If you want analytics and monitoring features, publish and run the migrations:

```bash
php artisan vendor:publish --provider="Curacel\LlmOrchestrator\LlmOrchestratorServiceProvider" --tag="migrations"
php artisan migrate
```

## Quick Start

### Configure Your Providers

Add API keys for any provider of your choice to `.env`:

```env
# OpenAI 
OPENAI_API_KEY=your-openai-key

# Anthropic Claude
CLAUDE_API_KEY=your-claude-key

# Google Gemini
GEMINI_API_KEY=your-gemini-key
```

Check the published configuration file at `config/llm-orchestrator.php` for all available options.

## Basic Usage

### Simple Prompts

```php
use Curacel\LlmOrchestrator\Facades\Llm;

// Simple prompt using default client
$response = Llm::prompt('Write a haiku about Laravel');
echo $response->content;

// Using specific client
$response = Llm::using('claude')->prompt('Explain quantum computing');
echo $response->content;
```

### Multi-turn Conversations

```php
use Curacel\LlmOrchestrator\DataObjects\Message;

$request = Llm::request()
    ->addMessage(Message::make('system', 'You are a helpful assistant'))
    ->addMessage(Message::make('user', 'What is Laravel?'))
    ->addMessage(Message::make('assistant', 'Laravel is a PHP framework...'))
    ->addMessage(Message::make('user', 'Tell me about its features'))
    ->build();

$result = Llm::send($request);
// or using a specific client
// $result = Llm::using('gemini')->send($request);
echo $result->content;
```

### Multimedia Content

Work with images, documents, audio, and other multimedia content. The package automatically adapts content in various formats (URLs, file paths, base64 data, or raw string data) to what the underlying provider expects.

> **⚠️ Provider Compatibility**: Each provider has different multimedia support capabilities. Ensure you check the documentation of the underlying provider for what content types are supported.

> **For implementation details**: Check the driver source code in `src/Drivers/` to see exactly how each content type is adapted and what metadata options are supported for each provider.

```php
use Curacel\LlmOrchestrator\DataObjects\Content;

// Basic image analysis - works with all providers
$request = Llm::request()
    ->addMessage(Message::make('user', [
        Content::text('What do you see in this image?'),
        Content::image('https://example.com/image.jpg')
    ]))
    ->build();

$result = Llm::send($request);

// Document processing (Claude and Gemini support, OpenAI treats as file)
$request = Llm::request()
    ->addMessage(Message::make('user', [
        Content::text('Summarize this document'),
        Content::document('/path/to/document.pdf')
    ]))
    ->build();

// Audio processing (OpenAI and Gemini support, Claude does NOT and will throw an error)
$request = Llm::request()
    ->addMessage(Message::make('user', [
        Content::text('Transcribe this audio'),
        Content::audio('/path/to/audio.wav')
    ]))
    ->build();

// Provider-specific metadata can be added based on driver implementation
$request = Llm::request()
    ->addMessage(Message::make('user', [
        Content::text('Process this file'),
        Content::file(
            path: '/path/to/file.pdf', // or URL, base64, raw data
            metadata: ['filename' => 'report.pdf'] // See driver code for supported options
        )
    ]))
    ->build();
```

### Complex Requests

Build sophisticated requests with custom configurations and multiple parameters:

```php
use Curacel\LlmOrchestrator\DataObjects\Message;
use Curacel\LlmOrchestrator\DataObjects\Content;

// Complex request with custom client configuration
$request = Llm::request()
    ->addMessage(Message::make('system', 'You are a data analyst expert'))
    ->addMessage(Message::make('user', [
        Content::text('Analyze this data and provide insights'),
        Content::file('path/to/data.csv', metadata: ['format' => 'csv'])
    ]))
    ->model('gpt-4o')
    ->temperature(0.2)
    ->maxTokens(2000)
    ->options([
        // Custom provider-specific options
        'top_p' => 0.9,
        'frequency_penalty' => 0.1,
        'presence_penalty' => 0.1,
    ])
    ->build();

// Use a specific custom-configured client
$result = Llm::using('openai-analysis')->send($request);

echo $result->content;
```


## Advanced Features

### Process Mapping

Route specific business processes to configured clients/models automatically. Process mappings can be configured in two ways:

**1. Database-driven (Dynamic)** - Create process mappings in the database for runtime management:

```php
use Curacel\LlmOrchestrator\Models\ProcessMapping;

ProcessMapping::create([
    'process_name' => 'content_generation',
    'client' => 'openai',
    'model' => 'gpt-4o',
    'is_active' => true,
    'description' => 'Generate marketing content'
]);
```

**2. Configuration-based (Static)** - Define process mappings in your config file:

```php
// config/llm-orchestrator.php
'process_mappings' => [
    'content_generation' => [
        'client' => 'openai',
        'model' => 'gpt-4o',
    ],
    'data_analysis' => [
        'client' => 'claude', 
        'model' => 'claude-3-5-sonnet-20241022',
    ],
],
```

**Priority Order**: Database mappings take precedence over config mappings. If no mapping is found in either, the default client and model are used.

```php
// Use in your application
$request = Llm::request()
    ->addMessage(Message::make('user', 'Generate a blog post about AI advancements'))
    ->build();

$response = Llm::forProcess('content_generation', $request);
echo $response->content;
```

### Function Calling & Tools

```php
use Curacel\LlmOrchestrator\DataObjects\Tool;
use Curacel\LlmOrchestrator\DataObjects\Property;

$tool = Tool::make(
    name: 'get_weather',
    description: 'Get current weather for a location',
    properties: [
        Property::make('location', 'string', 'The city name', required: true),
        Property::make('units', 'string', 'Temperature units (celsius/fahrenheit)'),
    ]
);

$request = Llm::request()
    ->prompt('What\'s the weather in Lagos?')
    ->addTool($tool)
    ->build();

$result = Llm::send($request);

// Check if function/tool was called
if (!empty($result->toolCalls)) {
    foreach ($result->toolCalls as $toolCall) {
        echo "Function: " . $toolCall->name;
        echo "Arguments: " . json_encode($toolCall->arguments);
    }
}
```

### Structured Output

```php
use Curacel\LlmOrchestrator\DataObjects\Schema;
use Curacel\LlmOrchestrator\DataObjects\Property;

// Define your desired output structure
$schema = Schema::make(
    name: 'PersonInfo',
    description: 'Information about a person',
    properties: [
        Property::make('name', 'string', 'Full name', required: true),
        Property::make('age', 'integer', 'Age in years', required: true),
        Property::make('occupation', 'string', 'Job title'),
        Property::make('skills', 'array', 'List of skills'),
    ]
);

$request = Llm::request()
    ->prompt('Extract person info from: John Doe is a 30-year-old software engineer skilled in PHP, Laravel, and React')
    ->asStructuredOutput($schema)
    ->build();

$result = Llm::send($request);

// Access structured output
$personData = $result->structuredOutput;
echo "Name: " . $personData['name'];
echo "Age: " . $personData['age'];
```

### Automatic Fallbacks

Configure automatic failover between clients if the primary client fails:

```php
// config/llm-orchestrator.php
'fallback' => [
    'enabled' => true,
    'clients' => ['claude', 'gemini'], // Fallback order if primary fails
],

// If OpenAI fails, automatically tries Claude, then Gemini.
$response = Llm::using('openai')->prompt('Generate a story');
```

### Raw Provider Access

Don't want the package to handle request building? Use `withRawPayload()` to send a custom payload directly to the provider's API:

```php
$request = Llm::request()
    ->withRawPayload([
        'model' => 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => 'Hello from raw payload']],
        'temperature' => 0.9,
        'top_p' => 0.8,           // OpenAI-specific parameter
        'frequency_penalty' => 0.1,
        'presence_penalty' => 0.1,
    ])
    ->build();

$result = Llm::using('openai')->send($request);

```

> **⚠️ Important**: When using `withRawPayload()`, all other request builder methods (model, temperature, maxTokens, etc.) are completely ignored. The raw payload is sent directly to the provider's API without any validation or transformation by the package.

## Analytics & Monitoring

> **⚠️ Note**: Analytics features require publishing and running the package migrations, as described in the [Installation](#installation) section.
> Also ensure analytics are enabled in the config file (`config/llm-orchestrator.php`).

### Execution Logging

Every LLM request is automatically logged with detailed information:
- Request/response data and metadata
- Token usage and cost calculations
- Client, driver, and model information

```php
use Curacel\LlmOrchestrator\Models\ExecutionLog;

// View recent execution logs
$logs = ExecutionLog::where('is_successful', true)
    ->where('created_at', '>=', now()->subDay())
    ->get();

foreach ($logs as $log) {
    echo "Client: {$log->client}, Cost: ${$log->cost}, Tokens: {$log->total_tokens}";
}
```

### Daily Metrics

Usage is automatically aggregated into daily metrics. Each row contains the metrics for one client/driver combination per day:

```php
use Curacel\LlmOrchestrator\Models\Metric;

// Get today's usage across all providers
$todayMetrics = Metric::whereDate('date', today())->get();

foreach ($todayMetrics as $metric) {
    echo "Client: {$metric->client}";
    echo "Driver: {$metric->driver}";
    echo "Total Requests: {$metric->total_requests}";
    echo "Success Rate: " . ($metric->successful_requests / $metric->total_requests * 100) . "%";
    echo "Total Cost: ${$metric->total_cost}";
    echo "Total Tokens: {$metric->total_tokens}";
}
```

## Error Handling

```php
use Curacel\LlmOrchestrator\Exceptions\LlmOrchestratorException;
use Curacel\LlmOrchestrator\Exceptions\AllClientsFailedException;
use Curacel\LlmOrchestrator\Exceptions\RequestFailedException;

try {
    $response = Llm::prompt('Generate content');
} catch (RequestFailedException $e) {
    // Handle API request failures
    Log::error('LLM request failed', $e->getContext());
} catch (AllClientsFailedException $e) {
    // Handle when all fallback clients fail
    Log::critical('All LLM providers failed', $e->getContext());
} catch (LlmOrchestratorException $e) {
    // Handle other orchestrator exceptions
    Log::error('LLM orchestrator error', $e->getContext());
}
```

## Custom Drivers

Create custom drivers for additional providers:

```php
use Curacel\LlmOrchestrator\Drivers\AbstractDriver;
use Curacel\LlmOrchestrator\DataObjects\Request;
use Curacel\LlmOrchestrator\DataObjects\Response;
use Illuminate\Support\Facades\Http;

class CustomLlmDriver extends AbstractDriver
{
    protected function getName(): string
    {
        return 'custom-llm';
    }
    
    protected function execute(Request $request): Response
    {
        // Prepare payload based on your provider's API from the Request object
        // Make API request call to your LLM provider
        // Map The provider response to the Response object
        // Return the  Response object    
        return Response::make();
    }
}

// Register in config/llm-orchestrator.php
'clients' => [
    'my-custom-llm' => [
        'driver' => 'custom',
        'via' => CustomLlmDriver::class,
        'api_key' => env('CUSTOM_LLM_API_KEY'),
        'base_url' => env('CUSTOM_LLM_BASE_URL'),
        'model' => 'custom-model-name',
    ],
],
```
## Configuration

Check the published configuration file at `config/llm-orchestrator.php` for all available settings. The config includes:

- **Client definitions**: Configure multiple LLM providers with different settings
- **Default settings**: Fallback values for timeout, retries, etc.
- **Model pricing**: Optional pricing information for cost tracking
- **Process mappings**: Static process-to-model routing
- **Fallback configuration**: Automatic failover between providers
- **Analytics settings**: Logging and metrics configuration

Key configuration concepts:
- **Clients** can use built-in drivers (`openai`, `claude`, `gemini`) or custom drivers
- **Built-in drivers** are included with the package and ready to use
- **Custom drivers** require you to implement the driver interface
- **Models** configuration is optional and only used for pricing/UI purposes
- **Process mappings** work from both database and config (database takes priority)


## Testing

```bash
# Run tests
composer test

# Run tests with coverage
composer test-coverage

# Run static analysis
composer analyse

# Format code
composer format
```


## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email olatayo.o@curacel.ai instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.

## Credits

- [Olayemi Olatayo](https://github.com/iamolayemi)
- [Curacel](https://curacel.ai)

