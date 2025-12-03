<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default LLM Client
    |--------------------------------------------------------------------------
    |
    | These are the default settings applied when sending requests to the LLM.
    | You can override them per client in the 'clients' section.
    |
    */
    'default' => [
        'client' => env('LLM_DEFAULT_CLIENT', 'openai'),
        'model' => env('LLM_DEFAULT_MODEL', 'gpt-4-mini'),
        'max_tokens' => env('LLM_DEFAULT_MAX_TOKENS', 2000),
        'timeout' => env('LLM_DEFAULT_TIMEOUT', 60),
        'max_retries' => env('LLM_DEFAULT_MAX_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Clients Configuration
    |--------------------------------------------------------------------------
    |
    | Define all LLM clients your application will use. Built-in drivers
    | include 'openai', 'claude', 'gemini'. For custom clients, set
    | 'driver' => 'custom' and provide a 'via' key pointing to your driver class.
    |
    | Example:
    | 'mistral' => [
    |     'driver' => 'custom',
    |     'via' => \App\LlmDrivers\MistralDriver::class,
    |     'api_key' => env('MISTRAL_API_KEY'),
    |     'base_url' => env('MISTRAL_API_BASE_URL'),
    |     'model' => 'mistral-7b-instruct-v0.1',
    | ],
    |
    */
    'clients' => [
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_API_BASE_URL', 'https://api.openai.com'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'max_tokens' => env('OPENAI_MAX_TOKENS'),
            'timeout' => env('OPENAI_TIMEOUT'),
            'max_retries' => env('OPENAI_MAX_RETRIES'),
        ],

        'claude' => [
            'driver' => 'claude',
            'api_key' => env('CLAUDE_API_KEY'),
            'anthropic_version' => env('CLAUDE_ANTHROPIC_VERSION', '2023-06-01'),
            'base_url' => env('CLAUDE_API_BASE_URL', 'https://api.anthropic.com'),
            'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
            'max_tokens' => env('CLAUDE_MAX_TOKENS'),
            'timeout' => env('CLAUDE_TIMEOUT'),
            'max_retries' => env('CLAUDE_MAX_RETRIES'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-pro'),
            'max_tokens' => env('GEMINI_MAX_TOKENS'),
            'timeout' => env('GEMINI_TIMEOUT'),
            'max_retries' => env('GEMINI_MAX_RETRIES'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models Configuration & Pricing
    |--------------------------------------------------------------------------
    |
    | Define the models available for each client and their pricing per 1M tokens.
    | This is optional but useful for cost estimation and selection in UIs.
    |
    | Structure:
    | 'client' => [
    |     'model-id' => [
    |         'name' => 'Display name',          // Human-readable model name
    |         'input' => 0.00,                   // Cost per 1M input tokens (USD)
    |         'output' => 0.00,                  // Cost per 1M output tokens (USD)
    |     ],
    | ],
    |
    */
    'models' => [
        'openai' => [
            'gpt-4o' => [
                'name' => 'GPT-4o',
                'input' => 2.50,
                'output' => 10.00,
            ],
            'gpt-4o-mini' => [
                'name' => 'GPT-4o Mini',
                'input' => 0.15,
                'output' => 0.60,
            ],
            'gpt-4.1' => [
                'name' => 'GPT-4.1',
                'input' => 2,
                'output' => 8,
            ],
        ],

        'claude' => [
            'claude-3-5-sonnet-20241022' => [
                'name' => 'Claude 3.5 Sonnet',
                'input' => 3.00,
                'output' => 15.00,
            ],
            'claude-3-5-haiku-20241022' => [
                'name' => 'Claude 3.5 Haiku',
                'input' => 0.80,
                'output' => 4.00,
            ],
        ],

        'gemini' => [
            'gemini-1.5-pro' => [
                'name' => 'Gemini 1.5 Pro',
                'input' => 1.25,
                'output' => 5.00,
            ],
            'gemini-1.5-flash' => [
                'name' => 'Gemini 1.5 Flash',
                'input' => 0.075,
                'output' => 0.30,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Processes Mapping
    |--------------------------------------------------------------------------
    |
    | Define internal LLM-powered processes and their default client/model.
    | This mapping allows the orchestrator to automatically route requests
    | to the appropriate LLM client and model for each process.
    |
    | Behavior:
    | - The system will first check the database for a process configuration
    |   if the corresponding table exists.
    | - If no database record is found, the mapping defined here will be used.
    | - If a process is missing from both, the default client and model will be applied.
    |
    |
    | Example structure:
    | 'process_name' => [
    |     'client' => 'openai',   // LLM client key
    |     'model' => 'gpt-4o',    // Default model for this process
    | ],
    */
    'process_mappings' => [
        // 'claim_analysis' => [
        //    'client' => 'openai',
        //    'model' => 'gpt-4o-mini',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Configuration
    |--------------------------------------------------------------------------
    |
    | If a request fails, you can specify a fallback sequence of clients.
    | Fallbacks only work if 'enabled' is true.
    */
    'fallback' => [
        'enabled' => env('LLM_FALLBACK_ENABLED', false),
        'clients' => explode(',', (string) env('LLM_FALLBACK_CLIENTS', 'claude,gemini')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Configure tables for storing metrics, process mappings, and execution logs.
    */
    'tables' => [
        'metrics' => env('LLM_METRICS_TABLE', 'llm_metrics'),
        'execution_logs' => env('LLM_EXECUTION_LOGS_TABLE', 'llm_execution_logs'),
        'process_mappings' => env('LLM_PROCESS_MAPPINGS_TABLE', 'llm_process_mappings'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Detailed logs of each LLM request/response for auditing and debugging.
    */
    'logging' => [
        'enabled' => env('LLM_LOGGING_ENABLED', false),
        'mechanism' => env('LLM_LOGGING_MECHANISM', 'sync'), // Options: sync, async
        'queue_connection' => env('LLM_LOGGING_QUEUE_CONNECTION', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Configuration
    |--------------------------------------------------------------------------
    |
    | Aggregated metrics for daily usage per client/model.
    */
    'metrics' => [
        'enabled' => env('LLM_METRICS_ENABLED', false),
        'mechanism' => env('LLM_METRICS_MECHANISM', 'sync'), // Options: sync, async
        'queue_connection' => env('LLM_METRICS_QUEUE_CONNECTION', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Enable Laravel Nova integration for managing LLM data.
    | Set enabled to false if you don't want Nova resources.
    |
    */
    'nova' => [
        'enabled' => env('LLM_NOVA_ENABLED', false),
    ],
];
