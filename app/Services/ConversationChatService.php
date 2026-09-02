<?php

namespace App\Services;

use App\ConversationStatus;
use App\Integrations\CreatesExternalActionProposals;
use App\Integrations\IntegrationContextRegistry;
use App\Integrations\IntegrationRouter;
use App\Jobs\ConsolidateConversationMemory;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use App\Models\Memory;
use App\Models\MemoryChange;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ConversationChatService
{
    public function __construct(
        private AiChatClient $client,
        private ChatContextBuilder $contextBuilder,
        private MemoryConsolidator $memoryConsolidator,
        private IntegrationRouter $integrationRouter,
        private IntegrationContextRegistry $integrationContexts,
        private CreatesExternalActionProposals $proposalCreator,
    ) {}

    /**
     * @return array{reply: string, conversation_title: string|null, calendar_error: string|null, integration_errors: array<string, string>, proposal: array<string, mixed>|null, proposals: array<int, array<string, mixed>>}
     */
    public function send(Conversation $conversation, string $content): array
    {
        $this->ensureChatExecutionBudget();

        if (! $conversation->isActive()) {
            throw new RuntimeException('Questa conversazione è chiusa.');
        }

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $content,
        ]);

        $conversation->update([
            'last_message_at' => now(),
        ]);

        $baseContext = $this->contextBuilder->build($conversation);
        $integrations = collect($this->integrationRouter->route($conversation, $baseContext['messages']))
            ->map(fn ($service) => $this->integrationContexts->prepare(
                $service,
                $conversation,
                $baseContext['messages'],
            ));
        $externalContext = $integrations
            ->pluck('context')
            ->filter()
            ->implode("\n\n");
        $context = $this->contextBuilder->build(
            $conversation,
            $externalContext !== '' ? $externalContext : null,
        );
        $reply = $this->client->complete($context['messages'], $context['system_prompt']);
        $raw = $this->client->lastRaw();

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply,
            'metadata' => $this->messageMetadata($raw),
        ]);

        $conversation->update(['last_message_at' => $assistantMessage->created_at]);

        $proposals = [];

        foreach ($integrations as $integration) {
            if ($integration->connection && $integration->proposalType && $integration->proposalPayload) {
                $createdProposal = $this->proposalCreator->create(
                    $conversation,
                    $assistantMessage,
                    $integration->connection,
                    $integration->proposalType,
                    $integration->proposalPayload,
                );
                $proposals[] = $this->proposalPayload($createdProposal);
            }
        }

        try {
            ConsolidateConversationMemory::dispatch($conversation->id);
        } catch (Throwable $exception) {
            report($exception);
        }

        return [
            'reply' => $reply,
            'conversation_title' => $conversation->fresh()->title,
            'calendar_error' => $integrations
                ->first(fn ($integration) => $integration->service->value === 'google_calendar')
                ?->error,
            'integration_errors' => $integrations
                ->filter(fn ($integration) => $integration->error !== null)
                ->mapWithKeys(fn ($integration): array => [
                    $integration->service->value => $integration->error,
                ])
                ->all(),
            'proposal' => $proposals[0] ?? null,
            'proposals' => $proposals,
        ];
    }

    /**
     * @return array{summary: string|null, title: string|null, changes: int}
     */
    public function close(Conversation $conversation): array
    {
        $this->ensureChatExecutionBudget();

        if (! $conversation->isActive()) {
            throw new RuntimeException('Questa conversazione è già chiusa.');
        }

        $result = $this->memoryConsolidator->consolidate($conversation, fullTranscript: true);

        DB::transaction(function () use ($conversation, $result): void {
            $conversation->update([
                'status' => ConversationStatus::Closed,
                'summary' => $result['summary'],
                'context_summary' => $result['summary'],
                'memory_consolidated_through_message_id' => null,
                'closed_at' => now(),
            ]);
            $conversation->messages()->delete();
        });

        return $result;
    }

    public function discard(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            if ($conversation->isActive()) {
                $this->revertConversationMemories($conversation);
            }

            $conversation->delete();
        });
    }

    private function revertConversationMemories(Conversation $conversation): void
    {
        $changes = MemoryChange::query()
            ->where('source_conversation_id', $conversation->id)
            ->orderBy('id')
            ->get()
            ->groupBy('memory_id');

        foreach ($changes as $memoryId => $conversationChanges) {
            $firstChange = $conversationChanges->first();

            if ($firstChange === null) {
                continue;
            }

            $laterForeignChange = MemoryChange::query()
                ->where('memory_id', $memoryId)
                ->where('id', '>', $firstChange->id)
                ->where(function ($query) use ($conversation): void {
                    $query->whereNull('source_conversation_id')
                        ->orWhere('source_conversation_id', '!=', $conversation->id);
                })
                ->exists();

            if ($laterForeignChange) {
                continue;
            }

            $memory = Memory::query()->find($memoryId);

            if (! $memory) {
                continue;
            }

            if ($firstChange->before === null) {
                $memory->delete();

                continue;
            }

            $before = $firstChange->before;
            $memory->update([
                'category' => $before['category'] ?? $memory->category,
                'memory_key' => $before['memory_key'] ?? $memory->memory_key,
                'content' => $before['content'] ?? $memory->content,
                'importance' => $before['importance'] ?? $memory->importance,
                'confidence' => $before['confidence'] ?? $memory->confidence,
                'source_conversation_id' => $before['source_conversation_id'] ?? null,
                'source_message_id' => $before['source_message_id'] ?? null,
                'last_reinforced_at' => $before['last_reinforced_at'] ?? $memory->last_reinforced_at,
                'archived_at' => $before['archived_at'] ?? null,
            ]);
        }

        MemoryChange::query()
            ->where('source_conversation_id', $conversation->id)
            ->delete();
    }

    /**
     * Ogni messaggio può richiedere più chiamate IA e integrazioni esterne in sequenza.
     */
    private function ensureChatExecutionBudget(): void
    {
        set_time_limit((int) config('ai.chat_request_timeout', 180));
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>|null
     */
    private function messageMetadata(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        return [
            'status' => Arr::get($raw, 'status'),
            'model' => Arr::get($raw, 'body.model', Arr::get($raw, 'model')),
            'usage' => Arr::get($raw, 'body.usage'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalPayload(ExternalActionProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'type' => $proposal->type->value,
            'status' => $proposal->status->value,
            'payload' => $proposal->payload,
            'title' => $proposal->type->title($proposal->payload),
            'provider_label' => $proposal->type->providerLabel(),
            'expires_at' => $proposal->expires_at->toIso8601String(),
            'confirm_url' => route('external-actions.confirm', $proposal),
            'reject_url' => route('external-actions.reject', $proposal),
        ];
    }
}
