<?php

/*
|--------------------------------------------------------------------------
| Artificial intelligence
|--------------------------------------------------------------------------
| Forecasting, reorder suggestions, anomaly detection, credit scoring and
| rule-based insights run inside the application and need no key at all.
|
| The assistant, AI-written insight summaries and product descriptions call
| a language model. Choose any provider below with AI_PROVIDER; all of them
| except "anthropic" speak the OpenAI-compatible chat API.
|
|   groq        free tier (about 1,000 requests a day), fast open models   https://console.groq.com/keys
|   gemini      free tier on Flash models (free-tier prompts may be used by Google to improve its products)
|                                                                          https://aistudio.google.com/apikey
|   openrouter  free ":free" models, 50 requests a day without credits     https://openrouter.ai/keys
|   ollama      runs on your own computer/server: free, private, no key    https://ollama.com
|   openai      any other OpenAI-compatible service (set AI_BASE_URL)
|   anthropic   Claude (paid)                                              https://console.anthropic.com
|
| AI_MODEL / AI_FAST_MODEL override the provider's default models.
| The assistant needs a model that supports tool (function) calling.
*/

return [
    'provider' => env('AI_PROVIDER', 'groq'),

    'providers' => [
        'groq' => [
            'driver' => 'openai',
            'label' => 'Groq',
            'key' => env('GROQ_API_KEY') ?: env('AI_API_KEY'),
            // AI_API_URL may be given as the full .../chat/completions address.
            'base_url' => preg_replace('#/chat/completions/?$#', '', (string) env('AI_API_URL', 'https://api.groq.com/openai/v1')),
            'model' => 'openai/gpt-oss-120b',
            'fast_model' => 'openai/gpt-oss-20b',
        ],
        'gemini' => [
            'driver' => 'openai',
            'label' => 'Google Gemini',
            'key' => env('GEMINI_API_KEY'),
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'model' => 'gemini-3.8-flash',
            'fast_model' => 'gemini-3.8-flash',
        ],
        'openrouter' => [
            'driver' => 'openai',
            'label' => 'OpenRouter',
            'key' => env('OPENROUTER_API_KEY'),
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'openrouter/free',
            'fast_model' => 'openrouter/free',
        ],
        'ollama' => [
            'driver' => 'openai',
            'label' => 'Ollama (local)',
            'key' => null,
            'key_required' => false,
            'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434/v1'),
            'model' => 'qwen3:8b',
            'fast_model' => 'qwen3:8b',
        ],
        'openai' => [
            'driver' => 'openai',
            'label' => 'OpenAI-compatible',
            'key' => env('AI_API_KEY'),
            'key_required' => (bool) env('AI_API_KEY_REQUIRED', true),
            'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => 'gpt-5-mini',
            'fast_model' => 'gpt-5-mini',
        ],
        'anthropic' => [
            'driver' => 'anthropic',
            'label' => 'Anthropic Claude',
            'key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'version' => '2023-06-01',
            'model' => 'claude-sonnet-5-5',
            'fast_model' => 'claude-haiku-5-5',
        ],
    ],

    // Optional overrides that apply to whichever provider is selected.
    'model' => env('AI_MODEL'),
    'fast_model' => env('AI_FAST_MODEL'),
    'timeout' => (int) env('AI_TIMEOUT', 60),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 2048),

    // Assistant safety limits.
    'assistant' => [
        'max_tool_rounds' => 6,
        'max_history_messages' => 20,
        'max_tool_result_chars' => 12000,
    ],
];
