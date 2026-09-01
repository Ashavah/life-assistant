<?php

namespace App;

enum ExternalActionType: string
{
    case CalendarCreateEvent = 'calendar.create_event';
    case DriveCreateFolder = 'drive.create_folder';
    case DriveCreateDocument = 'drive.create_document';
    case GmailCreateDraft = 'gmail.create_draft';
    case GmailSendMessage = 'gmail.send_message';
    case MicrosoftCreateDraft = 'microsoft_mail.create_draft';
    case MicrosoftSendMessage = 'microsoft_mail.send_message';
    case MicrosoftCreateEvent = 'microsoft_calendar.create_event';
    case OneDriveCreateFolder = 'microsoft_onedrive.create_folder';
    case OneDriveCreateFile = 'microsoft_onedrive.create_file';
    case SpotifyAddToPlaylist = 'spotify.add_to_playlist';
    case SpotifyAddToQueue = 'spotify.add_to_queue';
    case SpotifyStartPlayback = 'spotify.start_playback';
    case NotionCreatePage = 'notion.create_page';
    case NotionAppendBlocks = 'notion.append_blocks';
    case SlackPostMessage = 'slack.post_message';
    case SlackReply = 'slack.reply';
    case DropboxCreateFolder = 'dropbox.create_folder';
    case DropboxUploadText = 'dropbox.upload_text';
    case GitHubCreateIssue = 'github.create_issue';
    case GitHubCreateComment = 'github.create_comment';

    public function integrationService(): ?IntegrationService
    {
        return match ($this) {
            self::MicrosoftCreateDraft, self::MicrosoftSendMessage => IntegrationService::MicrosoftMail,
            self::MicrosoftCreateEvent => IntegrationService::MicrosoftCalendar,
            self::OneDriveCreateFolder, self::OneDriveCreateFile => IntegrationService::MicrosoftOneDrive,
            self::SpotifyAddToPlaylist, self::SpotifyAddToQueue, self::SpotifyStartPlayback => IntegrationService::Spotify,
            self::NotionCreatePage, self::NotionAppendBlocks => IntegrationService::Notion,
            self::SlackPostMessage, self::SlackReply => IntegrationService::Slack,
            self::DropboxCreateFolder, self::DropboxUploadText => IntegrationService::Dropbox,
            self::GitHubCreateIssue, self::GitHubCreateComment => IntegrationService::GitHub,
            default => null,
        };
    }

    public function gatewayAction(): string
    {
        return match ($this) {
            self::MicrosoftCreateDraft => 'create_draft',
            self::MicrosoftSendMessage => 'send_message',
            self::MicrosoftCreateEvent => 'create_event',
            self::OneDriveCreateFolder, self::DropboxCreateFolder => 'create_folder',
            self::OneDriveCreateFile => 'create_file',
            self::SpotifyAddToPlaylist => 'add_to_playlist',
            self::SpotifyAddToQueue => 'add_to_queue',
            self::SpotifyStartPlayback => 'start_playback',
            self::NotionCreatePage => 'create_page',
            self::NotionAppendBlocks => 'append_blocks',
            self::SlackPostMessage => 'post_message',
            self::SlackReply => 'reply',
            self::DropboxUploadText => 'upload_text',
            self::GitHubCreateIssue => 'create_issue',
            self::GitHubCreateComment => 'create_comment',
            default => $this->value,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function title(array $payload): string
    {
        $subject = (string) ($payload['summary'] ?? $payload['subject'] ?? $payload['title'] ?? $payload['name'] ?? $payload['path'] ?? '');

        return match ($this) {
            self::CalendarCreateEvent, self::MicrosoftCreateEvent => 'Crea evento: '.$subject,
            self::DriveCreateFolder, self::OneDriveCreateFolder, self::DropboxCreateFolder => 'Crea cartella: '.$subject,
            self::DriveCreateDocument, self::OneDriveCreateFile, self::DropboxUploadText => 'Crea file: '.$subject,
            self::GmailCreateDraft, self::MicrosoftCreateDraft => 'Crea bozza: '.$subject,
            self::GmailSendMessage, self::MicrosoftSendMessage => 'Invia email: '.$subject,
            self::SpotifyAddToPlaylist => 'Aggiungi brani alla playlist',
            self::SpotifyAddToQueue => 'Aggiungi brano alla coda',
            self::SpotifyStartPlayback => 'Avvia riproduzione Spotify',
            self::NotionCreatePage => 'Crea pagina Notion: '.$subject,
            self::NotionAppendBlocks => 'Aggiungi contenuto alla pagina Notion',
            self::SlackPostMessage => 'Pubblica messaggio Slack',
            self::SlackReply => 'Rispondi nel thread Slack',
            self::GitHubCreateIssue => 'Crea issue GitHub: '.$subject,
            self::GitHubCreateComment => 'Pubblica commento GitHub',
        };
    }

    public function providerLabel(): string
    {
        return match ($this) {
            self::CalendarCreateEvent, self::DriveCreateFolder, self::DriveCreateDocument,
            self::GmailCreateDraft, self::GmailSendMessage => 'Google',
            self::MicrosoftCreateDraft, self::MicrosoftSendMessage, self::MicrosoftCreateEvent,
            self::OneDriveCreateFolder, self::OneDriveCreateFile => 'Microsoft 365',
            self::SpotifyAddToPlaylist, self::SpotifyAddToQueue, self::SpotifyStartPlayback => 'Spotify',
            self::NotionCreatePage, self::NotionAppendBlocks => 'Notion',
            self::SlackPostMessage, self::SlackReply => 'Slack',
            self::DropboxCreateFolder, self::DropboxUploadText => 'Dropbox',
            self::GitHubCreateIssue, self::GitHubCreateComment => 'GitHub',
        };
    }
}
