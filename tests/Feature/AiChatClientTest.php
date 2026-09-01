<?php

use App\Services\AiChatClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'ai.api_key' => 'test-key',
        'ai.url' => 'https://api.deepseek.com/v1/chat/completions',
        'ai.model' => 'deepseek-chat',
    ]);
    Http::preventStrayRequests();
});

test('ritenta quando il provider restituisce un contenuto strutturato vuoto', function () {
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '']]]])
            ->push(['choices' => [['message' => ['content' => '{"intent":"list"}']]]]),
    ]);

    expect((new AiChatClient)->completeStructured([
        ['role' => 'user', 'content' => 'Cosa ho domani?'],
    ], 'Rispondi in JSON.'))->toBe(['intent' => 'list']);

    Http::assertSentCount(2);
});

test('fallisce solo dopo aver esaurito i tentativi', function () {
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '']]],
        ]),
    ]);

    expect(fn () => (new AiChatClient)->completeStructured([
        ['role' => 'user', 'content' => 'Cosa ho domani?'],
    ], 'Rispondi in JSON.'))->toThrow(RuntimeException::class, 'Risposta strutturata IA vuota dopo 3 tentativi.');

    Http::assertSentCount(3);
});
