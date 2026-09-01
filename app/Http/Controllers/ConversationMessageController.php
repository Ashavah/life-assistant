<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversationMessageRequest;
use App\Models\Conversation;
use App\Services\ConversationChatService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

class ConversationMessageController extends Controller
{
    public function store(
        StoreConversationMessageRequest $request,
        Conversation $conversation,
        ConversationChatService $chat,
    ): JsonResponse {
        $conversation->loadMissing('character');

        try {
            $result = $chat->send($conversation, $request->string('message')->trim()->toString());
        } catch (RequestException $exception) {
            report($exception);

            return response()->json($this->errorPayload(
                'L’endpoint IA ha rifiutato la richiesta. Controlla chiave, modello e URL.',
                [
                    'status' => $exception->response->status(),
                    'body' => $exception->response->json() ?? $exception->response->body(),
                ],
            ), 502);
        } catch (RuntimeException $exception) {
            return response()->json($this->errorPayload($exception->getMessage()), 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json($this->errorPayload(
                'Qualcosa è andato storto nel contatto con l’IA.',
            ), 500);
        }

        $payload = [
            'reply' => $result['reply'],
            'memory_changes' => $result['memory_changes'],
            'memory_error' => $result['memory_error'],
            'calendar_error' => $result['calendar_error'],
            'integration_errors' => $result['integration_errors'],
            'proposal' => $result['proposal'],
            'proposals' => $result['proposals'],
        ];

        if (config('ai.debug')) {
            $payload['raw'] = $result['raw'];
        }

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(string $message, ?array $raw = null): array
    {
        $payload = ['message' => $message];

        if (config('ai.debug') && $raw !== null) {
            $payload['raw'] = $raw;
        }

        return $payload;
    }
}
