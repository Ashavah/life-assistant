<?php

namespace App\Models;

use Database\Factories\KnowledgeIngestionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeIngestionItem extends Model
{
    /** @use HasFactory<KnowledgeIngestionItemFactory> */
    use HasFactory;

    protected $fillable = [
        'knowledge_ingestion_id',
        'character_id',
        'action',
        'memory_key',
        'category',
        'content',
        'importance',
        'confidence',
        'reason',
        'source_reference',
        'selected',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
            'importance' => 'integer',
            'confidence' => 'float',
            'selected' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function ingestion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeIngestion::class, 'knowledge_ingestion_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
