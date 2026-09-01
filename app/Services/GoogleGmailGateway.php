<?php

namespace App\Services;

use App\Contracts\GmailGateway;
use App\Exceptions\GoogleGatewayException;
use App\Models\ServiceConnection;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Draft;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;

class GoogleGmailGateway implements GmailGateway
{
    public function __construct(private GoogleTokenManager $tokens) {}

    public function search(ServiceConnection $connection, string $query): array
    {
        $gmail = $this->gmail($connection);

        try {
            $messages = $gmail->users_messages->listUsersMessages('me', [
                'q' => $query,
                'maxResults' => 15,
            ]);

            $results = collect($messages->getMessages())
                ->map(fn (Message $message): array => $this->metadata(
                    $gmail->users_messages->get('me', $message->getId(), [
                        'format' => 'metadata',
                        'metadataHeaders' => ['From', 'To', 'Subject', 'Date'],
                    ]),
                ))
                ->all();
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }

        $this->touch($connection);

        return $results;
    }

    public function read(ServiceConnection $connection, string $messageId): array
    {
        try {
            $message = $this->gmail($connection)->users_messages->get('me', $messageId, [
                'format' => 'full',
            ]);
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }

        $this->touch($connection);

        return array_merge($this->metadata($message), [
            'body' => mb_substr($this->textBody($message->getPayload()), 0, 12000),
        ]);
    }

    public function createDraft(
        ServiceConnection $connection,
        array $payload,
        string $idempotencyKey,
    ): array {
        $gmail = $this->gmail($connection);
        $messageId = $this->internetMessageId($idempotencyKey);

        try {
            $existing = collect($gmail->users_drafts->listUsersDrafts('me', [
                'q' => 'rfc822msgid:'.$messageId,
                'maxResults' => 1,
            ])->getDrafts())->first();

            if ($existing instanceof Draft) {
                return [
                    'id' => (string) $existing->getId(),
                    'message_id' => (string) $existing->getMessage()?->getId(),
                    'status' => 'draft',
                ];
            }

            $draft = $gmail->users_drafts->create('me', new Draft([
                'message' => $this->message($payload, $messageId),
            ]));
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }

        $this->touch($connection);

        return [
            'id' => (string) $draft->getId(),
            'message_id' => (string) $draft->getMessage()?->getId(),
            'status' => 'draft',
        ];
    }

    public function send(
        ServiceConnection $connection,
        array $payload,
        string $idempotencyKey,
    ): array {
        $gmail = $this->gmail($connection);
        $messageId = $this->internetMessageId($idempotencyKey);

        try {
            $existing = collect($gmail->users_messages->listUsersMessages('me', [
                'q' => 'in:sent rfc822msgid:'.$messageId,
                'maxResults' => 1,
            ])->getMessages())->first();

            if ($existing instanceof Message) {
                return [
                    'id' => (string) $existing->getId(),
                    'thread_id' => (string) $existing->getThreadId(),
                    'status' => 'sent',
                ];
            }

            $sent = $gmail->users_messages->send('me', $this->message($payload, $messageId));
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }

        $this->touch($connection);

        return [
            'id' => (string) $sent->getId(),
            'thread_id' => (string) $sent->getThreadId(),
            'status' => 'sent',
        ];
    }

    private function gmail(ServiceConnection $connection): Gmail
    {
        return new Gmail($this->tokens->client($connection));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function message(array $payload, string $messageId): Message
    {
        $headers = [
            'To: '.implode(', ', $payload['to']),
            'Subject: '.$this->header((string) $payload['subject']),
            'Message-ID: <'.$messageId.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (($payload['cc'] ?? []) !== []) {
            $headers[] = 'Cc: '.implode(', ', $payload['cc']);
        }

        $raw = implode("\r\n", $headers)."\r\n\r\n".(string) $payload['body'];
        $message = new Message;
        $message->setRaw(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));

        return $message;
    }

    private function internetMessageId(string $idempotencyKey): string
    {
        return $idempotencyKey.'@life-assistant.local';
    }

    private function header(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Message $message): array
    {
        $headers = collect($message->getPayload()?->getHeaders() ?? [])
            ->mapWithKeys(fn (MessagePartHeader $header): array => [
                strtolower((string) $header->getName()) => (string) $header->getValue(),
            ]);

        return [
            'id' => (string) $message->getId(),
            'thread_id' => (string) $message->getThreadId(),
            'from' => $headers->get('from'),
            'to' => $headers->get('to'),
            'subject' => $headers->get('subject', '(senza oggetto)'),
            'date' => $headers->get('date'),
            'snippet' => (string) $message->getSnippet(),
        ];
    }

    private function textBody(?MessagePart $part): string
    {
        if ($part === null) {
            return '';
        }

        if ($part->getMimeType() === 'text/plain' && $part->getBody()?->getData()) {
            return $this->decode((string) $part->getBody()->getData());
        }

        foreach ($part->getParts() ?? [] as $child) {
            $text = $this->textBody($child);

            if ($text !== '') {
                return $text;
            }
        }

        return $part->getBody()?->getData()
            ? $this->decode((string) $part->getBody()->getData())
            : '';
    }

    private function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    private function touch(ServiceConnection $connection): void
    {
        $connection->update(['last_used_at' => now()]);
    }
}
