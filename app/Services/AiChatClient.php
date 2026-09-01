<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

class AiChatClient
{
    /**
     * In modalità JSON alcuni provider restituiscono sporadicamente un contenuto vuoto,
     * quindi la chiamata strutturata viene ritentata prima di considerarla fallita.
     */
    private const STRUCTURED_ATTEMPTS = 3;

    /** @var array<string, mixed>|null */
    protected ?array $lastRaw = null;

    /**
     * Payload grezzo dell'ultima chiamata, per il pannello di debug della chat.
     *
     * @return array<string, mixed>|null
     */
    public function lastRaw(): ?array
    {
        return $this->lastRaw;
    }

    public function isConfigured(): bool
    {
        $key = config('ai.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function complete(array $messages, ?string $systemPrompt = null): string
    {
        $response = $this->send(
            $this->withSystemPrompt(
                $messages,
                $systemPrompt ?? (string) config('ai.system_prompt'),
            ),
            ['temperature' => (float) config('ai.temperature', 0.7)],
        );

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Risposta IA vuota o non valida.');
        }

        return trim($content);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function completeStructured(array $messages, string $systemPrompt): array
    {
        $content = null;

        for ($attempt = 1; $attempt <= self::STRUCTURED_ATTEMPTS && $content === null; $attempt++) {
            $response = $this->send(
                $this->withSystemPrompt($messages, $systemPrompt),
                [
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'],
                ],
            );

            $candidate = data_get($response->json(), 'choices.0.message.content');

            if (is_string($candidate) && trim($candidate) !== '') {
                $content = trim($candidate);
            }
        }

        if ($content === null) {
            throw new RuntimeException(sprintf(
                'Risposta strutturata IA vuota dopo %d tentativi.',
                self::STRUCTURED_ATTEMPTS,
            ));
        }

        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('La risposta IA per la memoria non è JSON valido.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('La risposta IA per la memoria ha una struttura non valida.');
        }

        return $decoded;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function withSystemPrompt(array $messages, string $systemPrompt): array
    {
        return array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        );
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    private function send(array $messages, array $options = []): Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('AI non configurata: imposta AI_KEY nel file .env.');
        }

        $url = $this->completionsUrl((string) config('ai.url'));

        if ($url === '') {
            throw new RuntimeException('AI non configurata: imposta AI_URL nel file .env.');
        }

        $response = Http::withToken((string) config('ai.api_key'))
            ->connectTimeout((int) config('ai.connect_timeout', 10))
            ->timeout((int) config('ai.timeout', 120))
            ->acceptJson()
            ->post($url, array_merge([
                'model' => (string) config('ai.model'),
                'messages' => $messages,
            ], $options));

        $this->lastRaw = [
            'url' => $url,
            'model' => (string) config('ai.model'),
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];

        if (config('ai.debug')) {
            Log::debug('AI raw response', $this->lastRaw);
        }

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response;
    }

    private function completionsUrl(string $configured): string
    {
        $configured = rtrim($configured, '/');

        if ($configured === '') {
            return '';
        }

        if (str_ends_with($configured, '/chat/completions')) {
            return $configured;
        }

        return $configured.'/chat/completions';
    }
}
