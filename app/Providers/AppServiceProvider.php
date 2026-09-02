<?php

namespace App\Providers;

use App\Contracts\CalendarGateway;
use App\Contracts\DriveGateway;
use App\Contracts\GmailGateway;
use App\Contracts\RemoteIntegrationGateway;
use App\Integrations\GenericOAuthDriver;
use App\Integrations\GoogleOAuthDriver;
use App\Integrations\HttpRemoteIntegrationGateway;
use App\Integrations\OAuthDriverRegistry;
use App\Services\GoogleCalendarGateway;
use App\Services\GoogleDriveGateway;
use App\Services\GoogleGmailGateway;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CalendarGateway::class, GoogleCalendarGateway::class);
        $this->app->bind(DriveGateway::class, GoogleDriveGateway::class);
        $this->app->bind(GmailGateway::class, GoogleGmailGateway::class);
        $this->app->bind(RemoteIntegrationGateway::class, HttpRemoteIntegrationGateway::class);
        $this->app->singleton(OAuthDriverRegistry::class, fn ($app): OAuthDriverRegistry => new OAuthDriverRegistry([
            $app->make(GoogleOAuthDriver::class),
            $app->make(GenericOAuthDriver::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive(
            'markdown',
            fn (string $expression): string => "<?php echo \App\Services\MarkdownRenderer::toHtml({$expression}); ?>",
        );
    }
}
