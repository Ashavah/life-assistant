<?php

namespace App\Integrations;

use App\IntegrationService;
use App\Models\Conversation;
use Illuminate\Support\Str;

class IntegrationRouter
{
    public function __construct(private ServiceConnectionResolver $connections) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, IntegrationService>
     */
    public function route(Conversation $conversation, array $messages): array
    {
        $lastMessage = collect($messages)
            ->reverse()
            ->first(fn (array $message): bool => $message['role'] === 'user');
        $text = Str::lower((string) ($lastMessage['content'] ?? ''));

        if ($text === '') {
            return [];
        }

        $scores = collect(IntegrationService::cases())
            ->mapWithKeys(fn (IntegrationService $service): array => [
                $service->value => $this->score($service, $text)
                    + ($this->connections->forService($conversation->user_id, $service) ? 3 : 0),
            ])
            ->filter(fn (int $score): bool => $score > 3)
            ->sortDesc()
            ->take((int) config('integrations.max_services_per_turn', 2));

        return $scores->keys()
            ->map(fn (string $value): IntegrationService => IntegrationService::from($value))
            ->values()
            ->all();
    }

    private function score(IntegrationService $service, string $text): int
    {
        $keywords = match ($service) {
            IntegrationService::GoogleCalendar => ['google calendar', 'gcalendar', 'agenda google'],
            IntegrationService::GoogleDrive => ['google drive', 'drive google', 'documento google', ' drive'],
            IntegrationService::GoogleGmail => ['gmail'],
            IntegrationService::MicrosoftMail => ['outlook', 'posta microsoft', 'email microsoft'],
            IntegrationService::MicrosoftCalendar => ['calendario outlook', 'calendario microsoft', 'agenda microsoft'],
            IntegrationService::MicrosoftOneDrive => ['onedrive', 'one drive'],
            IntegrationService::Spotify => ['spotify', 'playlist', 'brano', 'musica', 'canzone', 'ascoltando'],
            IntegrationService::Notion => ['notion', 'database notion', 'pagina notion'],
            IntegrationService::Slack => ['slack', 'canale', 'thread'],
            IntegrationService::Dropbox => ['dropbox'],
            IntegrationService::GitHub => ['github', 'repository', 'repo ', 'issue', 'pull request', ' pr '],
        };
        $generic = match ($service) {
            IntegrationService::GoogleCalendar, IntegrationService::MicrosoftCalendar => ['calendario', 'agenda', 'evento', 'appuntamento', 'domani', 'impegni', 'fissami'],
            IntegrationService::GoogleDrive, IntegrationService::MicrosoftOneDrive, IntegrationService::Dropbox, IntegrationService::Notion => ['file', 'cartella', 'documento'],
            IntegrationService::GoogleGmail, IntegrationService::MicrosoftMail => ['email', 'mail', 'posta', 'messaggio'],
            default => [],
        };

        $score = collect($keywords)->sum(fn (string $keyword): int => str_contains($text, $keyword) ? 10 : 0);

        return $score + collect($generic)->sum(fn (string $keyword): int => str_contains($text, $keyword) ? 2 : 0);
    }
}
