<?php

namespace App\Http\Controllers;

use App\ConversationStatus;
use App\Http\Requests\StoreConversationRequest;
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
}
