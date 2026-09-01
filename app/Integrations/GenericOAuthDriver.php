<?php

namespace App\Integrations;

use App\Contracts\OAuthDriver;
use App\Exceptions\IntegrationGatewayException;
use App\Models\ServiceConnection;
use App\ServiceProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class GenericOAuthDriver implements OAuthDriver
{
    public function supports(ServiceProvider $provider): bool
    {
        return ! in_array($provider, [
            ServiceProvider::Google,
            ServiceProvider::GoogleCalendar,
            ServiceProvider::GoogleDrive,
            ServiceProvider::GoogleGmail,
        ], true);
    }

    public function isConfigured(ServiceProvider $provider): bool
    {
        $key = $provider->configurationKey();

        return filled(config("services.{$key}.client_id"))
            && filled(config("services.{$key}.client_secret"))
            && filled(config("services.{$key}.redirect_uri"));
    }

    public function authorizationUrl(
        ServiceProvider $provider,
        string $state,
        ?string $codeChallenge = null,
    ): string {
        $this->assertConfigured($provider);
        $key = $provider->configurationKey();
        $parameters = [
            'client_id' => config("services.{$key}.client_id"),
            'redirect_uri' => config("services.{$key}.redirect_uri"),
            'response_type' => 'code',
            'state' => $state,
        ];

        if ($provider === ServiceProvider::Notion) {
            $parameters['owner'] = 'user';
        } elseif ($provider === ServiceProvider::Slack) {
            $parameters['user_scope'] = implode(',', $provider->scopes());
        } else {
            $parameters['scope'] = implode(' ', $provider->scopes());
        }

        if ($provider === ServiceProvider::Microsoft) {
            $parameters['response_mode'] = 'query';
            $parameters['prompt'] = 'select_account';
        }

        if ($provider === ServiceProvider::Dropbox) {
            $parameters['token_access_type'] = 'offline';
        }

        if ($codeChallenge !== null && ! in_array($provider, [ServiceProvider::Notion, ServiceProvider::Slack], true)) {
            $parameters['code_challenge'] = $codeChallenge;
            $parameters['code_challenge_method'] = 'S256';
        }

        return (string) config("integrations.providers.{$key}.authorize_url").'?'.http_build_query($parameters);
    }

    public function exchange(
        ServiceProvider $provider,
        string $code,
        ?string $codeVerifier = null,
    ): array {
        $this->assertConfigured($provider);
        $key = $provider->configurationKey();
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config("services.{$key}.redirect_uri"),
            'client_id' => config("services.{$key}.client_id"),
            'client_secret' => config("services.{$key}.client_secret"),
        ];

        if ($codeVerifier !== null) {
            $payload['code_verifier'] = $codeVerifier;
        }

        try {
            $response = $this->tokenRequest($provider, $payload)
                ->post((string) config("integrations.providers.{$key}.token_url"), $payload)
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            throw new IntegrationGatewayException(
                'Il provider non ha restituito un token valido.',
                ['provider' => $provider->value],
                previous: $exception,
            );
        }

        if (! is_array($response)) {
            throw new IntegrationGatewayException('Risposta OAuth non valida.', ['provider' => $provider->value]);
        }

        $accessToken = $provider === ServiceProvider::Slack
            ? Arr::get($response, 'authed_user.access_token')
            : Arr::get($response, 'access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new IntegrationGatewayException(
                (string) (Arr::get($response, 'error_description') ?: Arr::get($response, 'error') ?: 'Token OAuth mancante.'),
                ['provider' => $provider->value],
            );
        }

        $metadata = $this->identity($provider, $accessToken, $response);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $this->nullableString(
                $provider === ServiceProvider::Slack
                    ? Arr::get($response, 'authed_user.refresh_token')
                    : Arr::get($response, 'refresh_token'),
            ),
            'expires_in' => $this->nullableInteger(
                $provider === ServiceProvider::Slack
                    ? Arr::get($response, 'authed_user.expires_in')
                    : Arr::get($response, 'expires_in'),
            ),
            'scopes' => $this->scopes($provider, $response),
            'metadata' => $metadata,
        ];
    }

    public function revoke(ServiceConnection $connection): void
    {
        $provider = $connection->provider;
        $key = $provider->configurationKey();
        $url = config("integrations.providers.{$key}.revoke_url");

        if (! is_string($url) || $url === '') {
            return;
        }

        if ($provider === ServiceProvider::GitHub) {
            Http::withBasicAuth(
                (string) config('services.github_app.client_id'),
                (string) config('services.github_app.client_secret'),
            )->delete($url.'/'.config('services.github_app.client_id').'/grant', [
                'access_token' => $connection->access_token,
            ])->throw();

            return;
        }

        $request = $this->request();

        if ($provider === ServiceProvider::Notion) {
            $request->withBasicAuth(
                (string) config('services.notion.client_id'),
                (string) config('services.notion.client_secret'),
            )->withHeaders(['Notion-Version' => (string) config('integrations.providers.notion.notion_version')])
                ->post($url, ['token' => $connection->access_token])
                ->throw();

            return;
        }

        if ($provider === ServiceProvider::Slack) {
            $request->withToken($connection->access_token)->post($url)->throw();

            return;
        }

        $request->withToken($connection->access_token)->post($url)->throw();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tokenRequest(ServiceProvider $provider, array $payload): PendingRequest
    {
        $request = $this->request()->asForm()->acceptJson();

        if ($provider === ServiceProvider::Notion) {
            return $this->request()
                ->acceptJson()
                ->asJson()
                ->withBasicAuth(
                    (string) config('services.notion.client_id'),
                    (string) config('services.notion.client_secret'),
                )
                ->withHeaders(['Notion-Version' => (string) config('integrations.providers.notion.notion_version')]);
        }

        if (in_array($provider, [ServiceProvider::Spotify, ServiceProvider::Dropbox], true)) {
            return $request->withBasicAuth(
                (string) $payload['client_id'],
                (string) $payload['client_secret'],
            );
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    private function identity(ServiceProvider $provider, string $accessToken, array $token): array
    {
        if ($provider === ServiceProvider::Notion) {
            return array_filter([
                'account_id' => Arr::get($token, 'owner.user.id'),
                'account_name' => Arr::get($token, 'owner.user.name'),
                'workspace_id' => Arr::get($token, 'workspace_id'),
                'workspace_name' => Arr::get($token, 'workspace_name'),
                'bot_id' => Arr::get($token, 'bot_id'),
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $key = $provider->configurationKey();
        $url = (string) config("integrations.providers.{$key}.identity_url");
        $request = $this->request()->withToken($accessToken);

        $identity = $provider === ServiceProvider::Dropbox
            ? $request->withBody('', 'application/json')->post($url)->throw()->json()
            : $request->get($url)->throw()->json();

        if (! is_array($identity)) {
            return [];
        }

        return array_filter([
            'account_id' => Arr::get($identity, 'id')
                ?? Arr::get($identity, 'account_id')
                ?? Arr::get($identity, 'user_id'),
            'account_name' => Arr::get($identity, 'display_name')
                ?? Arr::get($identity, 'displayName')
                ?? Arr::get($identity, 'name.display_name')
                ?? Arr::get($identity, 'name')
                ?? Arr::get($identity, 'login')
                ?? Arr::get($identity, 'user'),
            'account_email' => Arr::get($identity, 'email')
                ?? Arr::get($identity, 'mail')
                ?? Arr::get($identity, 'userPrincipalName'),
            'workspace_id' => Arr::get($identity, 'team_id'),
            'workspace_name' => Arr::get($identity, 'team'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<int, string>
     */
    private function scopes(ServiceProvider $provider, array $token): array
    {
        $scope = $provider === ServiceProvider::Slack
            ? Arr::get($token, 'authed_user.scope')
            : Arr::get($token, 'scope');

        if (! is_string($scope) || $scope === '') {
            return $provider->scopes();
        }

        return array_values(array_filter(preg_split('/[,\s]+/', $scope) ?: []));
    }

    private function request(): PendingRequest
    {
        return Http::connectTimeout((int) config('integrations.connect_timeout', 5))
            ->timeout((int) config('integrations.timeout', 15));
    }

    private function assertConfigured(ServiceProvider $provider): void
    {
        if (! $this->isConfigured($provider)) {
            throw new IntegrationGatewayException($provider->label().' OAuth non è configurato.');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
