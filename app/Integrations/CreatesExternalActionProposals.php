<?php

namespace App\Integrations;

use App\ExternalActionStatus;
use App\ExternalActionType;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use App\Models\Message;
use App\Models\ServiceConnection;
use Illuminate\Support\Str;

class CreatesExternalActionProposals
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        Conversation $conversation,
        Message $message,
        ServiceConnection $connection,
        ExternalActionType $type,
        array $payload,
    ): ExternalActionProposal {
        return ExternalActionProposal::query()->create([
            'service_connection_id' => $connection->id,
            'character_id' => $conversation->character_id,
            'conversation_id' => $conversation->id,
            'source_message_id' => $message->id,
            'type' => $type,
            'status' => ExternalActionStatus::Pending,
            'idempotency_key' => 'la'.substr(hash('sha256', (string) Str::uuid()), 0, 40),
            'payload' => $payload,
            'expires_at' => now()->addDay(),
        ]);
    }
}
