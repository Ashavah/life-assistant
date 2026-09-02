<?php

namespace App\Integrations;

use App\IntegrationService;
use App\Models\Conversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IntegrationRouter
{
    /**
     * Oltre questa lunghezza un messaggio senza riferimenti espliciti viene
     * considerato un cambio di argomento e non un seguito della domanda precedente.
     */
    private const FOLLOW_UP_CHARACTERS = 120;

    public function __construct(private ServiceConnectionResolver $connections) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<int, IntegrationService>
     */
    public function route(Conversation $conversation, array $messages): array
    {
        $userTexts = collect($messages)
            ->filter(fn (array $message): bool => $message['role'] === 'user')
            ->map(fn (array $message): string => Str::lower((string) $message['content']))
            ->values();
        $text = (string) $userTexts->last();

        if ($text === '') {
            return [];
        }

        $scores = $this->scores($text);

        if ($scores->max() === 0 && mb_strlen($text) <= self::FOLLOW_UP_CHARACTERS) {
            $scores = $this->scores((string) $userTexts->slice(-2, 1)->first());
        }

        return $scores
            ->map(fn (int $score, string $value): int => $score + (
                $this->connections->forService($conversation->user_id, IntegrationService::from($value)) ? 3 : 0
            ))
            ->filter(fn (int $score): bool => $score > 3)
            ->sortDesc()
            ->take((int) config('integrations.max_services_per_turn', 2))
            ->keys()
            ->map(fn (string $value): IntegrationService => IntegrationService::from($value))
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, int>
     */
    private function scores(string $text): Collection
    {
        return collect(IntegrationService::cases())
            ->mapWithKeys(fn (IntegrationService $service): array => [
                $service->value => $this->score($service, $text),
            ]);
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
            IntegrationService::Spotify => ['spotify', 'playlist', 'brano', 'brani', 'canzon', 'musica', 'album', 'artista', 'artisti', 'traccia', 'tracce', 'ascoltando', 'ascoltat'],
            IntegrationService::Notion => ['notion', 'database notion', 'pagina notion'],
            IntegrationService::Slack => ['slack', 'canale', 'thread'],
            IntegrationService::Dropbox => ['dropbox'],
            IntegrationService::GitHub => ['github', 'repository', 'repo ', 'issue', 'pull request', ' pr '],
        };
        $generic = match ($service) {
            IntegrationService::GoogleCalendar, IntegrationService::MicrosoftCalendar => ['calendario', 'agenda', 'evento', 'appuntamento', 'domani', 'impegni', 'fissami'],
            IntegrationService::GoogleDrive, IntegrationService::MicrosoftOneDrive, IntegrationService::Dropbox, IntegrationService::Notion => ['file', 'cartella', 'documento'],
            IntegrationService::GoogleGmail, IntegrationService::MicrosoftMail => ['email', 'mail', 'posta', 'messaggio'],
            IntegrationService::Spotify => ['ascolti', 'riproduci', 'cuffie'],
            default => [],
        };

        $score = collect($keywords)->sum(fn (string $keyword): int => str_contains($text, $keyword) ? 10 : 0);

        return $score + collect($generic)->sum(fn (string $keyword): int => str_contains($text, $keyword) ? 2 : 0);
    }
}
