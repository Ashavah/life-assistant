<?php

namespace App;

enum IntegrationService: string
{
    case GoogleCalendar = 'google_calendar';
    case GoogleDrive = 'google_drive';
    case GoogleGmail = 'google_gmail';
    case MicrosoftMail = 'microsoft_mail';
    case MicrosoftCalendar = 'microsoft_calendar';
    case MicrosoftOneDrive = 'microsoft_onedrive';
    case Spotify = 'spotify';
    case Notion = 'notion';
    case Slack = 'slack';
    case Dropbox = 'dropbox';
    case GitHub = 'github';

    public function provider(): ServiceProvider
    {
        return match ($this) {
            self::GoogleCalendar, self::GoogleDrive, self::GoogleGmail => ServiceProvider::Google,
            self::MicrosoftMail, self::MicrosoftCalendar, self::MicrosoftOneDrive => ServiceProvider::Microsoft,
            self::Spotify => ServiceProvider::Spotify,
            self::Notion => ServiceProvider::Notion,
            self::Slack => ServiceProvider::Slack,
            self::Dropbox => ServiceProvider::Dropbox,
            self::GitHub => ServiceProvider::GitHub,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GoogleCalendar => 'Google Calendar',
            self::GoogleDrive => 'Google Drive',
            self::GoogleGmail => 'Gmail',
            self::MicrosoftMail => 'Outlook',
            self::MicrosoftCalendar => 'Calendario Outlook',
            self::MicrosoftOneDrive => 'OneDrive',
            self::Spotify => 'Spotify',
            self::Notion => 'Notion',
            self::Slack => 'Slack',
            self::Dropbox => 'Dropbox',
            self::GitHub => 'GitHub',
        };
    }
}
