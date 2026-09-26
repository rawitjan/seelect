<?php

return [
    'ai_enabled' => env('CONTRACTOR_AI_ENABLED', true),
    'ai_provider' => env('CONTRACTOR_AI_PROVIDER', 'openai-compatible'),
    'ai_model' => env('CONTRACTOR_AI_MODEL', 'qwen3-8'),
    'chat_timeout' => (int) env('CONTRACTOR_CHAT_TIMEOUT', 12),
    'cache_store' => env('CONTRACTOR_CACHE_STORE', 'file'),
];
