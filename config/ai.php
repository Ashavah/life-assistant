<?php

return [
    'url' => env('AI_URL', 'https://api.deepseek.com/v1/chat/completions'),
    'api_key' => env('AI_KEY'),
    'model' => env('AI_MODEL', 'deepseek-chat'),
    'timeout' => (int) env('AI_TIMEOUT', 120),
    'chat_request_timeout' => (int) env('AI_CHAT_REQUEST_TIMEOUT', 180),
    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),
    'temperature' => (float) env('AI_TEMPERATURE', 0.7),
    'max_history_messages' => (int) env('AI_MAX_HISTORY_MESSAGES', 30),
    'max_memories_per_character' => (int) env('AI_MAX_MEMORIES_PER_CHARACTER', 40),
    'memory_incremental_messages' => (int) env('AI_MEMORY_INCREMENTAL_MESSAGES', 16),
    'memory_close_messages' => (int) env('AI_MEMORY_CLOSE_MESSAGES', 40),
    'conversation_summary_max_characters' => (int) env('AI_CONVERSATION_SUMMARY_MAX_CHARACTERS', 8000),
    'system_prompt' => env(
        'AI_SYSTEM_PROMPT',
        'Sei Life Assistant, un assistente personale in italiano. Rispondi in modo chiaro, concreto e conciso.',
    ),
    'dialogue' => [
        'provider' => env('AI_PREMIUM_PROVIDER'),
        'url' => env('AI_PREMIUM_URL'),
        'api_key' => env('AI_PREMIUM_KEY'),
        'model' => env('AI_PREMIUM_MODEL', 'gpt-5.4-mini'),
        'timeout' => (int) env('AI_PREMIUM_TIMEOUT', 120),
        'temperature' => (float) env('AI_PREMIUM_TEMPERATURE', 0.7),
    ],
];
