<?php

namespace App\Models;

use App\ConversationStatus;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'character_id',
        'title',
        'status',
        'summary',
        'context_summary',
        'memory_consolidated_through_message_id',
        'last_message_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'memory_consolidated_through_message_id' => 'integer',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
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
        static::saving(function (Conversation $conversation): void {
            if ($conversation->character_id !== null) {
                $conversation->user_id = Character::query()
                    ->findOrFail($conversation->character_id)
                    ->user_id;
            }
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest('id');
    }

    public function memoryChanges(): HasMany
    {
        return $this->hasMany(MemoryChange::class, 'source_conversation_id');
    }

    public function externalActionProposals(): HasMany
    {
        return $this->hasMany(ExternalActionProposal::class);
    }

    public function isActive(): bool
    {
        return $this->status === ConversationStatus::Active;
    }
}
