<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\MemoryConsolidator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ConsolidateConversationMemory implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 600;

    public function __construct(public int $conversationId) {}

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(MemoryConsolidator $consolidator): void
    {
        $conversation = Conversation::query()
            ->with('character')
            ->find($this->conversationId);

        if (! $conversation || ! $conversation->isActive()) {
            return;
        }

        $consolidator->consolidate($conversation);
    }
}
