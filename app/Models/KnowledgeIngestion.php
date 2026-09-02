<?php

namespace App\Models;

use App\KnowledgeIngestionDecision;
use App\KnowledgeIngestionStatus;
use App\KnowledgeSourceType;
use Database\Factories\KnowledgeIngestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeIngestion extends Model
{
    /** @use HasFactory<KnowledgeIngestionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'character_id',
        'status',
        'decision',
        'source_type',
        'original_filename',
        'mime_type',
        'size_bytes',
        'content_hash',
        'disk',
        'path',
        'temporary_text',
        'error_message',
        'item_count',
        'expires_at',
        'processed_at',
        'decided_at',
        'purged_at',
    ];

    protected $hidden = [
        'temporary_text',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeIngestionStatus::class,
            'decision' => KnowledgeIngestionDecision::class,
            'source_type' => KnowledgeSourceType::class,
            'size_bytes' => 'integer',
            'temporary_text' => 'encrypted',
            'item_count' => 'integer',
            'expires_at' => 'datetime',
            'processed_at' => 'datetime',
            'decided_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeIngestionItem::class);
    }

    public function memoryChanges(): HasMany
    {
        return $this->hasMany(MemoryChange::class, 'source_knowledge_ingestion_id');
    }

    protected static function booted(): void
    {
        static::saving(function (KnowledgeIngestion $ingestion): void {
            if ($ingestion->character_id !== null) {
                $ingestion->user_id = Character::query()
                    ->findOrFail($ingestion->character_id)
                    ->user_id;
            }
        });
    }
}
