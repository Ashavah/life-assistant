<?php

namespace App\Models;

use Database\Factories\MemoryChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryChange extends Model
{
    /** @use HasFactory<MemoryChangeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'memory_id',
        'character_id',
        'source_conversation_id',
        'source_message_id',
        'source_knowledge_ingestion_id',
        'action',
        'reason',
        'before',
        'after',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function memory(): BelongsTo
    {
        return $this->belongsTo(Memory::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::saving(function (MemoryChange $change): void {
            if ($change->character_id !== null) {
                $change->user_id = Character::query()
                    ->findOrFail($change->character_id)
                    ->user_id;
            }
        });
    }

    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'source_conversation_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    public function sourceKnowledgeIngestion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeIngestion::class, 'source_knowledge_ingestion_id');
    }
}
