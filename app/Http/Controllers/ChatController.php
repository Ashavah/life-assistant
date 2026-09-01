<?php

namespace App\Http\Controllers;

use App\CharacterSlug;
use App\Integrations\OAuthDriverRegistry;
use App\Models\Character;
use App\Models\Conversation;
use App\ServiceProvider;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(Request $request, OAuthDriverRegistry $oauthDrivers): View
    {
        $characters = Character::query()
            ->whereBelongsTo($request->user())
            ->withCount([
                'memories as active_memories_count' => fn ($query) => $query->active(),
            ])
            ->with(['conversations' => function ($query): void {
                $query->latest('last_message_at')
                    ->latest('id')
                    ->limit(20);
            }])
            ->orderBy('sort_order')
            ->get();

        $requestedSlug = $request->string('character', CharacterSlug::Global->value)->toString();
        $selectedCharacter = $characters->first(
            fn (Character $character): bool => $character->slug === $requestedSlug,
        ) ?? $characters->first();

        $selectedConversation = $this->selectedConversation(
            $request,
            $selectedCharacter,
        );

        $selectedConversation?->load([
            'messages',
            'externalActionProposals' => fn ($query) => $query->orderBy('id'),
        ]);

        $connections = $request->user()
            ->serviceConnections()
            ->get()
            ->keyBy(fn ($connection): string => $connection->provider->value);
        $providers = collect(ServiceProvider::accountProviders())
            ->filter(fn (ServiceProvider $provider): bool => in_array(
                $provider->value,
                config('integrations.account_providers', ['google']),
                true,
            ))
            ->values();
        $configured = $providers->mapWithKeys(
            fn (ServiceProvider $provider): array => [
                $provider->value => $oauthDrivers->for($provider)->isConfigured($provider),
            ],
        );

        return view('home', [
            'characters' => $characters,
            'selectedCharacter' => $selectedCharacter,
            'selectedConversation' => $selectedConversation,
            'integrationConnections' => $connections,
            'integrationProviders' => $providers,
            'integrationConfigured' => $configured,
            'settingsOpen' => $request->boolean('settings'),
            'accountSettingsOpen' => $request->boolean('account_settings'),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    private function selectedConversation(
        Request $request,
        ?Character $character,
    ): ?Conversation {
        if (! $character) {
            return null;
        }

        $requestedConversationId = $request->integer('conversation');

        if ($requestedConversationId > 0) {
            return $character->conversations->firstWhere('id', $requestedConversationId);
        }

        return $character->conversations
            ->first(fn (Conversation $conversation): bool => $conversation->isActive());
    }
}
