<?php

namespace App\Services;

use App\ConversationStatus;
use App\Integrations\CreatesExternalActionProposals;
use App\Integrations\IntegrationContextRegistry;
use App\Integrations\IntegrationRouter;
use App\Models\Conversation;
use App\Models\ExternalActionProposal;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
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
     * @return array{reply: string, raw: array<string, mixed>|null, memory_changes: int|null, memory_error: string|null, calendar_error: string|null, integration_errors: array<string, string>, proposal: array<string, mixed>|null, proposals: array<int, array<string, mixed>>}
     */
    public function send(Conversation $conversation, string $content): array
    {
        if (! $conversation->isActive()) {
            throw new RuntimeException('Questa conversazione è chiusa.');
        }

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $content,
        ]);

        $conversation->update([
            'title' => $conversation->title ?: Str::limit($content, 60),
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

        $memoryChanges = null;
        $memoryError = null;

        if ($conversation->character->is_global) {
            try {
                $result = $this->memoryConsolidator->consolidate($conversation);
                $memoryChanges = $result['changes'];
            } catch (Throwable $exception) {
                report($exception);
                $memoryError = 'La risposta è salva, ma l’aggiornamento automatico delle memorie non è riuscito.';
            }
        }

        return [
            'reply' => $reply,
            'raw' => $raw,
            'memory_changes' => $memoryChanges,
            'memory_error' => $memoryError,
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
     * @return array{summary: string|null, changes: int}
     */
    public function close(Conversation $conversation): array
    {
        if (! $conversation->isActive()) {
            throw new RuntimeException('Questa conversazione è già chiusa.');
        }

        $result = $this->memoryConsolidator->consolidate($conversation);

        $conversation->update([
            'status' => ConversationStatus::Closed,
            'summary' => $result['summary'],
            'closed_at' => now(),
        ]);

        return $result;
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
