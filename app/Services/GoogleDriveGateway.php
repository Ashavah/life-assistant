<?php

namespace App\Services;

use App\Contracts\DriveGateway;
use App\Exceptions\GoogleGatewayException;
use App\Models\ServiceConnection;
use Google\Service\Docs;
use Google\Service\Docs\BatchUpdateDocumentRequest;
use Google\Service\Docs\InsertTextRequest;
use Google\Service\Docs\Request as DocsRequest;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception as GoogleServiceException;

class GoogleDriveGateway implements DriveGateway
{
    public function __construct(private GoogleTokenManager $tokens) {}

    public function search(ServiceConnection $connection, string $query): array
    {
        try {
            $files = $this->drive($connection)->files->listFiles([
                'q' => sprintf(
                    "trashed = false and name contains '%s'",
                    str_replace(['\\', "'"], ['\\\\', "\\'"], $query),
                ),
                'pageSize' => 20,
                'orderBy' => 'modifiedTime desc',
                'fields' => 'files(id,name,mimeType,modifiedTime,webViewLink,parents)',
            ]);
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }

        $this->touch($connection);

        return collect($files->getFiles())
            ->map(fn (DriveFile $file): array => $this->normalize($file))
            ->all();
    }

    public function read(ServiceConnection $connection, string $fileId): array
    {
        $drive = $this->drive($connection);

        try {
            $file = $drive->files->get($fileId, [
                'fields' => 'id,name,mimeType,modifiedTime,webViewLink,parents,size',
            ]);
            $content = $this->content($drive, $file);
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }

        $this->touch($connection);

        return array_merge($this->normalize($file), [
            'content' => mb_substr($content, 0, 12000),
        ]);
    }

    public function createFolder(
        ServiceConnection $connection,
        array $payload,
        string $idempotencyKey,
    ): array {
        $drive = $this->drive($connection);

        if ($existing = $this->findByIdempotencyKey($drive, $idempotencyKey)) {
            return $this->normalize($existing);
        }

        $folder = new DriveFile([
            'name' => $payload['name'],
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => array_values(array_filter([$payload['parent_id'] ?? null])),
            'appProperties' => ['life_assistant_key' => $idempotencyKey],
        ]);

        try {
            $created = $drive->files->create($folder, [
                'fields' => 'id,name,mimeType,modifiedTime,webViewLink,parents',
            ]);
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }

        $this->touch($connection);

        return $this->normalize($created);
    }

    public function createDocument(
        ServiceConnection $connection,
        array $payload,
        string $idempotencyKey,
    ): array {
        $client = $this->tokens->client($connection);
        $drive = new Drive($client);

        if ($existing = $this->findByIdempotencyKey($drive, $idempotencyKey)) {
            return $this->normalize($existing);
        }

        try {
            $document = $drive->files->create(new DriveFile([
                'name' => $payload['name'],
                'mimeType' => 'application/vnd.google-apps.document',
                'parents' => array_values(array_filter([$payload['parent_id'] ?? null])),
                'appProperties' => ['life_assistant_key' => $idempotencyKey],
            ]), [
                'fields' => 'id,name,mimeType,modifiedTime,webViewLink,parents',
            ]);

            $content = trim((string) ($payload['content'] ?? ''));

            if ($content !== '') {
                (new Docs($client))->documents->batchUpdate(
                    $document->getId(),
                    new BatchUpdateDocumentRequest([
                        'requests' => [
                            new DocsRequest([
                                'insertText' => new InsertTextRequest([
                                    'location' => ['index' => 1],
                                    'text' => $content,
                                ]),
                            ]),
                        ],
                    ]),
                );
            }
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }

        $this->touch($connection);

        return $this->normalize($document);
    }

    private function drive(ServiceConnection $connection): Drive
    {
        return new Drive($this->tokens->client($connection));
    }

    private function findByIdempotencyKey(Drive $drive, string $key): ?DriveFile
    {
        try {
            return collect($drive->files->listFiles([
                'q' => "trashed = false and appProperties has { key='life_assistant_key' and value='{$key}' }",
                'pageSize' => 1,
                'fields' => 'files(id,name,mimeType,modifiedTime,webViewLink,parents)',
            ])->getFiles())->first();
        } catch (GoogleServiceException $exception) {
            throw GoogleGatewayException::fromGoogle($exception);
        }
    }

    private function content(Drive $drive, DriveFile $file): string
    {
        if ($file->getMimeType() === 'application/vnd.google-apps.document') {
            return (string) $drive->files
                ->export($file->getId(), 'text/plain', ['alt' => 'media'])
                ->getBody();
        }

        $allowed = ['text/plain', 'text/markdown', 'text/csv', 'application/json'];

        if (! in_array($file->getMimeType(), $allowed, true)) {
            return '[Il formato del file non può essere inserito nella chat]';
        }

        return (string) $drive->files
            ->get($file->getId(), ['alt' => 'media'])
            ->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(DriveFile $file): array
    {
        return [
            'id' => (string) $file->getId(),
            'name' => (string) $file->getName(),
            'mime_type' => (string) $file->getMimeType(),
            'modified_at' => $file->getModifiedTime(),
            'web_link' => $file->getWebViewLink(),
            'parent_ids' => $file->getParents() ?? [],
        ];
    }

    private function touch(ServiceConnection $connection): void
    {
        $connection->update(['last_used_at' => now()]);
    }
}
