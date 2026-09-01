<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\ConversationChatService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

class ClosedConversationController extends Controller
{
    public function store(Conversation $conversation, ConversationChatService $chat): JsonResponse
    {
        $this->authorize('close', $conversation);
        $conversation->loadMissing('character');

        try {
            $result = $chat->close($conversation);
        } catch (RequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Non sono riuscito a consolidare la memoria: la conversazione resta aperta.',
            ], 502);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Errore durante il consolidamento della memoria.',
            ], 500);
        }

        return response()->json([
            'message' => "Conversazione chiusa. {$result['changes']} memorie aggiornate.",
            'summary' => $result['summary'],
            'memory_changes' => $result['changes'],
            'url' => route('home', ['character' => $conversation->character->slug]),
        ]);
    }
}
