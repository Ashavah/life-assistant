<?php

namespace App\Integrations;

use App\ExternalActionType;
use App\IntegrationService;
use App\Models\ServiceConnection;

final readonly class IntegrationPreparation
{
    /**
     * @param  array<string, mixed>|null  $proposalPayload
     */
    public function __construct(
        public IntegrationService $service,
        public ?string $context = null,
        public ?ServiceConnection $connection = null,
        public ?ExternalActionType $proposalType = null,
        public ?array $proposalPayload = null,
        public ?string $error = null,
    ) {}
}
