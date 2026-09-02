<?php

namespace App;

use Google\Service\Calendar;
use Google\Service\Drive;
use Google\Service\Gmail;

enum ServiceProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case Spotify = 'spotify';
    case Notion = 'notion';
    case Slack = 'slack';
    case Dropbox = 'dropbox';
    case GitHub = 'github';

    /**
     * Connessioni Google precedenti al consenso unico di suite.
     */
    case GoogleCalendar = 'google_calendar';
    case GoogleDrive = 'google_drive';
    case GoogleGmail = 'google_gmail';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Microsoft => 'Microsoft 365',
            self::Spotify => 'Spotify',
            self::Notion => 'Notion',
            self::Slack => 'Slack',
            self::Dropbox => 'Dropbox',
            self::GitHub => 'GitHub',
            self::GoogleCalendar => 'Google Calendar',
            self::GoogleDrive => 'Google Drive',
            self::GoogleGmail => 'Gmail',
        };
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Google => array_values(array_unique([
                'openid',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
                Calendar::CALENDAR_EVENTS,
                Drive::DRIVE_READONLY,
                Drive::DRIVE_FILE,
                Gmail::GMAIL_READONLY,
                Gmail::GMAIL_COMPOSE,
                Gmail::GMAIL_SEND,
            ])),
            self::Microsoft => [
                'openid',
                'profile',
                'offline_access',
                'User.Read',
                'Mail.Read',
                'Mail.Send',
                'Calendars.Read',
                'Calendars.ReadWrite',
                'Files.Read',
                'Files.ReadWrite',
            ],
            self::Spotify => [
                'user-read-private',
                'user-read-email',
                'user-read-playback-state',
                'user-read-currently-playing',
                'user-read-recently-played',
                'user-top-read',
                'playlist-read-private',
                'playlist-modify-private',
                'user-modify-playback-state',
            ],
            self::Notion => [],
            self::Slack => [
                'search:read',
                'channels:read',
                'channels:history',
                'groups:history',
                'im:history',
                'mpim:history',
                'chat:write',
            ],
            self::Dropbox => [
                'account_info.read',
                'files.metadata.read',
                'files.content.read',
                'files.content.write',
            ],
            self::GitHub => [],
            self::GoogleCalendar => [Calendar::CALENDAR_EVENTS],
            self::GoogleDrive => [Drive::DRIVE_READONLY, Drive::DRIVE_FILE],
            self::GoogleGmail => [Gmail::GMAIL_READONLY, Gmail::GMAIL_COMPOSE, Gmail::GMAIL_SEND],
        };
    }

    public function configurationKey(): string
    {
        return match ($this) {
            self::Google, self::GoogleCalendar, self::GoogleDrive, self::GoogleGmail => 'google',
            self::Microsoft => 'microsoft',
            self::Spotify => 'spotify',
            self::Notion => 'notion',
            self::Slack => 'slack_oauth',
            self::Dropbox => 'dropbox',
            self::GitHub => 'github_app',
        };
    }

    public function isLegacy(): bool
    {
        return in_array($this, [
            self::GoogleCalendar,
            self::GoogleDrive,
            self::GoogleGmail,
        ], true);
    }

    /**
     * @return array<int, self>
     */
    public static function accountProviders(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $provider): bool => ! $provider->isLegacy(),
        ));
    }
}
