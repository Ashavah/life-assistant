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

test('dopo un contenuto vuoto ritenta senza la modalità json nativa', function () {
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '']]]])
            ->push(['choices' => [['message' => ['content' => "```json\n{\"intent\":\"list\"}\n```"]]]]),
    ]);

    expect((new AiChatClient)->completeStructured([
        ['role' => 'user', 'content' => 'Cosa ho domani?'],
    ], 'Rispondi in JSON.'))->toBe(['intent' => 'list']);

    $requests = [];
    Http::assertSent(function ($request) use (&$requests): bool {
        $requests[] = $request->data();

        return true;
    });

    expect($requests[0])->toHaveKey('response_format')
        ->and($requests[1])->not->toHaveKey('response_format');
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

test('il dialogo usa il modello premium quando è configurato', function () {
    config([
        'ai.dialogue.api_key' => 'premium-key',
        'ai.dialogue.url' => 'https://api.openai.com/v1/chat/completions',
        'ai.dialogue.model' => 'gpt-5.4-mini',
        'ai.dialogue.timeout' => 120,
        'ai.dialogue.temperature' => 0.7,
    ]);
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Ciao.']]],
        ]),
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '{"intent":"list"}']]],
        ]),
    ]);

    $client = new AiChatClient;

    expect($client->complete([
        ['role' => 'user', 'content' => 'Ciao'],
    ]))->toBe('Ciao.');
    expect($client->completeStructured([
        ['role' => 'user', 'content' => 'Cosa ho domani?'],
    ], 'Rispondi in JSON.'))->toBe(['intent' => 'list']);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/chat/completions'
        && $request->data()['model'] === 'gpt-5.4-mini'
        && $request->hasHeader('Authorization', 'Bearer premium-key'));
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.deepseek.com/v1/chat/completions'
        && $request->data()['model'] === 'deepseek-chat'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && ($request->data()['response_format']['type'] ?? null) === 'json_object');
});

test('senza premium il dialogo ricade sul modello strutturato', function () {
    config([
        'ai.dialogue.api_key' => '',
        'ai.dialogue.url' => '',
    ]);
    Http::fake([
        'https://api.deepseek.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Ciao da DeepSeek.']]],
        ]),
    ]);

    expect((new AiChatClient)->complete([
        ['role' => 'user', 'content' => 'Ciao'],
    ]))->toBe('Ciao da DeepSeek.');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.deepseek.com/v1/chat/completions'
        && $request->data()['model'] === 'deepseek-chat');
});
