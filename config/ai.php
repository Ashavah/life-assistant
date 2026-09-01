<?php

return [
    'url' => env('AI_URL', 'https://api.deepseek.com/v1/chat/completions'),
    'api_key' => env('AI_KEY'),
    'model' => env('AI_MODEL', 'deepseek-chat'),
    'timeout' => (int) env('AI_TIMEOUT', 120),
    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 10),
    'temperature' => (float) env('AI_TEMPERATURE', 0.7),
    'debug' => (bool) env('AI_DEBUG', false),
    'max_history_messages' => (int) env('AI_MAX_HISTORY_MESSAGES', 30),
    'max_memories_per_character' => (int) env('AI_MAX_MEMORIES_PER_CHARACTER', 40),
    'system_prompt' => env(
        'AI_SYSTEM_PROMPT',
        'Sei Life Assistant, un assistente personale in italiano. Rispondi in modo chiaro, concreto e conciso.',
    ),
];
