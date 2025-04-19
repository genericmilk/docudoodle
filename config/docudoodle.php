<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key
    |--------------------------------------------------------------------------
    |
    | This key is used to authenticate with the OpenAI API.
    | You can get your API key from https://platform.openai.com/account/api-keys
    |
    */
    'openai_api_key' => env('OPENAI_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Claude API Key
    |--------------------------------------------------------------------------
    |
    | This key is used to authenticate with the Claude API.
    |
    */
    'claude_api_key' => env('CLAUDE_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | The default model to use for generating documentation.
    |
    */
    'default_model' => env('DOCUDOODLE_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Tokens
    |--------------------------------------------------------------------------
    |
    | The maximum number of tokens to use for API calls.
    |
    */
    'max_tokens' => env('DOCUDOODLE_MAX_TOKENS', 10000),

    /*
    |--------------------------------------------------------------------------
    | Default Extensions
    |--------------------------------------------------------------------------
    |
    | The default file extensions to process.
    |
    */
    'default_extensions' => ['php', 'yaml', 'yml'],

    /*
    |--------------------------------------------------------------------------
    | Default Skip Directories
    |--------------------------------------------------------------------------
    |
    | The default directories to skip during processing.
    |
    */
    'default_skip_dirs' => ['vendor/', 'node_modules/', 'tests/', 'cache/'],

    /*
    |--------------------------------------------------------------------------
    | Ollama Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the Ollama API which runs locally.
    | 
    | host: The host where Ollama is running (default: localhost)
    | port: The port Ollama is listening on (default: 11434)
    |
    */
    'ollama_host' => env('OLLAMA_HOST', 'localhost'),
    'ollama_port' => env('OLLAMA_PORT', '11434'),

    /*
    |--------------------------------------------------------------------------
    | Gemini API Key
    |--------------------------------------------------------------------------
    |
    | This key is used to authenticate with the Gemini API.
    |
    */
    'gemini_api_key' => env('GEMINI_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Default API Provider
    |--------------------------------------------------------------------------
    |
    | The default API provider to use for generating documentation.
    | Supported values: 'openai', 'ollama', 'claude', 'gemini'
    |
    */
    'default_api_provider' => env('DOCUDOODLE_API_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configure the caching mechanism to skip unchanged files.
    |
    | use_cache: Enable or disable caching (default: true).
    | cache_file_path: Absolute path to the cache file. If null or empty,
    |                  it defaults to '.docudoodle_cache.json' inside the output directory.
    |
    */
    'use_cache' => env('DOCUDOODLE_USE_CACHE', true),
    'cache_file_path' => env('DOCUDOODLE_CACHE_PATH', null),
];