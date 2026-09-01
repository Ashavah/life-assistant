<?php

namespace App\Models;

use App\ExternalActionStatus;
use App\ExternalActionType;
use Database\Factories\ExternalActionProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalActionProposal extends Model
{
    /** @use HasFactory<ExternalActionProposalFactory> */
    use HasFactory;

    protected $fillable = [
        'service_connection_id',
        'character_id',
        'conversation_id',
        'source_message_id',
        'type',
        'status',
        'idempotency_key',
        'payload',
        'result',
        'error_message',
        'expires_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExternalActionStatus::class,
            'type' => ExternalActionType::class,
            'payload' => 'encrypted:array',
            'result' => 'encrypted:array',
            'expires_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }
}
