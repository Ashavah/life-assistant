<?php

namespace App\Services;

use App\Contracts\CalendarGateway;
use App\Contracts\DriveGateway;
use App\Contracts\GmailGateway;
use App\Contracts\RemoteIntegrationGateway;
use App\ExternalActionStatus;
use App\ExternalActionType;
use App\Models\ExternalActionProposal;
use RuntimeException;
use Throwable;

class ExternalActionService
{
    public function __construct(
        private CalendarGateway $calendar,
        private DriveGateway $drive,
        private GmailGateway $gmail,
        private RemoteIntegrationGateway $remote,
    ) {}

    public function confirm(ExternalActionProposal $proposal): ExternalActionProposal
    {
        $proposal->refresh();

        if ($proposal->status === ExternalActionStatus::Completed) {
            return $proposal;
        }

        if ($proposal->expires_at->isPast()) {
            $proposal->update(['status' => ExternalActionStatus::Expired]);

            throw new RuntimeException('Questa proposta è scaduta.');
        }

        $claimed = ExternalActionProposal::query()
            ->whereKey($proposal)
            ->whereIn('status', [
                ExternalActionStatus::Pending->value,
                ExternalActionStatus::Failed->value,
            ])
            ->update([
                'status' => ExternalActionStatus::Executing,
                'error_message' => null,
            ]);

        if ($claimed !== 1) {
            throw new RuntimeException('Questa proposta è già in elaborazione o non può più essere confermata.');
        }

        try {
            $result = match ($proposal->type) {
                ExternalActionType::CalendarCreateEvent => $this->calendar->createEvent(
                    $proposal->serviceConnection,
                    $proposal->payload,
                    $proposal->idempotency_key,
                ),
                ExternalActionType::DriveCreateFolder => $this->drive->createFolder(
                    $proposal->serviceConnection,
                    $proposal->payload,
                    $proposal->idempotency_key,
                ),
                ExternalActionType::DriveCreateDocument => $this->drive->createDocument(
                    $proposal->serviceConnection,
                    $proposal->payload,
                    $proposal->idempotency_key,
                ),
                ExternalActionType::GmailCreateDraft => $this->gmail->createDraft(
                    $proposal->serviceConnection,
                    $proposal->payload,
                    $proposal->idempotency_key,
                ),
                ExternalActionType::GmailSendMessage => $this->gmail->send(
                    $proposal->serviceConnection,
                    $proposal->payload,
                    $proposal->idempotency_key,
                ),
                default => $this->remote->write(
                    $proposal->serviceConnection,
                    $proposal->type->integrationService()
                        ?? throw new RuntimeException('Tipo di azione esterna non supportato.'),
                    $proposal->type->gatewayAction(),
                    $proposal->payload,
                    $proposal->idempotency_key,
                ),
            };

            $proposal->update([
                'status' => ExternalActionStatus::Completed,
                'result' => $result,
                'executed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $proposal->update([
                'status' => ExternalActionStatus::Failed,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $proposal->refresh();
    }

    public function reject(ExternalActionProposal $proposal): ExternalActionProposal
    {
        if ($proposal->status !== ExternalActionStatus::Pending) {
            throw new RuntimeException('Questa proposta non può più essere rifiutata.');
        }

        $proposal->update(['status' => ExternalActionStatus::Rejected]);

        return $proposal->refresh();
    }
}
