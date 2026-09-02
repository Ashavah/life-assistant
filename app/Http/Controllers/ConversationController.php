<?php

namespace App\Http\Controllers;

use App\ConversationStatus;
use App\Http\Requests\StoreConversationRequest;
use App\Models\Conversation;
use App\Services\ConversationChatService;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
{
    public function store(StoreConversationRequest $request): JsonResponse
    {
        $character = $request->user()
            ->characters()
            ->findOrFail($request->integer('character_id'));
        $conversation = $character->conversations()->create([
            'status' => ConversationStatus::Active,
        ]);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => 'Nuova conversazione',
            ],
            'url' => route('home', [
                'character' => $character->slug,
                'conversation' => $conversation->id,
            ]),
        ], 201);
    }

    public function destroy(Conversation $conversation, ConversationChatService $chat): JsonResponse
    {
        $this->authorize('delete', $conversation);
        $conversation->loadMissing('character');
        $url = route('home', ['character' => $conversation->character->slug]);

        $chat->discard($conversation);

        return response()->json([
            'message' => 'Conversazione eliminata. Nessuna memoria è stata salvata da questa chat.',
            'url' => $url,
        ]);
    }
}
