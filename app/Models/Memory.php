<?php

namespace App\Models;

use Database\Factories\MemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Memory extends Model
{
    /** @use HasFactory<MemoryFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'character_id',
        'category',
        'memory_key',
        'content',
        'importance',
        'confidence',
        'source_conversation_id',
        'source_message_id',
        'last_reinforced_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'importance' => 'integer',
            'confidence' => 'float',
            'last_reinforced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
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
        static::saving(function (Memory $memory): void {
            if ($memory->character_id !== null) {
                $memory->user_id = Character::query()
                    ->findOrFail($memory->character_id)
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

    public function changes(): HasMany
    {
        return $this->hasMany(MemoryChange::class);
    }
}
