<?php

namespace App\Integrations;

use App\ExternalActionType;
use App\IntegrationService;
use App\Models\Conversation;
use App\Services\CalendarChatContextService;
use App\Services\DriveChatContextService;
use App\Services\GmailChatContextService;

class IntegrationContextRegistry
{
    public function __construct(
        private CalendarChatContextService $calendar,
        private DriveChatContextService $drive,
        private GmailChatContextService $gmail,
        private GenericIntegrationChatContext $generic,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function prepare(
        IntegrationService $service,
        Conversation $conversation,
        array $messages,
    ): IntegrationPreparation {
        return match ($service) {
            IntegrationService::GoogleCalendar => $this->fromArray(
                $service,
                $this->calendar->prepare($conversation, $messages),
                ExternalActionType::CalendarCreateEvent,
            ),
            IntegrationService::GoogleDrive => $this->fromArray(
                $service,
                $this->drive->prepare($conversation, $messages),
            ),
            IntegrationService::GoogleGmail => $this->fromArray(
                $service,
                $this->gmail->prepare($conversation, $messages),
            ),
            default => $this->generic->prepare($service, $conversation, $messages),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function fromArray(
        IntegrationService $service,
        array $result,
        ?ExternalActionType $defaultProposalType = null,
    ): IntegrationPreparation {
        $connection = $result['connection'] ?? null;

        if (! $connection) {
            return new IntegrationPreparation(
                service: $service,
                context: $service->label().' non è collegato. Se serve, invita l’utente a collegarlo dal pannello Il mio account.',
            );
        }

        return new IntegrationPreparation(
            service: $service,
            context: $result['context'] ?? null,
            connection: $connection,
            proposalType: $result['proposal_type'] ?? (
                ($result['proposal_payload'] ?? null) ? $defaultProposalType : null
            ),
            proposalPayload: $result['proposal_payload'] ?? null,
            error: $result['error'] ?? null,
        );
    }
}
