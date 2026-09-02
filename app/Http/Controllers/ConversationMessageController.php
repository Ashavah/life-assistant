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

            return response()->json([
                'message' => 'L’endpoint IA ha rifiutato la richiesta. Controlla chiave, modello e URL.',
            ], 502);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Qualcosa è andato storto nel contatto con l’IA.',
            ], 500);
        }

        return response()->json([
            'reply' => $result['reply'],
            'conversation_title' => $result['conversation_title'],
            'calendar_error' => $result['calendar_error'],
            'integration_errors' => $result['integration_errors'],
            'proposal' => $result['proposal'],
            'proposals' => $result['proposals'],
        ]);
    }
}
