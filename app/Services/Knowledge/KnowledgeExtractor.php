<?php

namespace App\Services\Knowledge;

use App\KnowledgeSourceType;
use App\Models\KnowledgeIngestion;
use App\Services\AiChatClient;
use Illuminate\Support\Facades\Storage;
use Imagick;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser;
use ZipArchive;

class KnowledgeExtractor
{
    public function __construct(private AiChatClient $client) {}

    public function extract(KnowledgeIngestion $ingestion): string
    {
        if ($ingestion->source_type === KnowledgeSourceType::Text) {
            return $this->normalize((string) $ingestion->temporary_text);
        }

        if (! $ingestion->disk || ! $ingestion->path) {
            throw new RuntimeException('La sorgente temporanea non è disponibile.');
        }

        $disk = Storage::disk($ingestion->disk);
        $path = $disk->path($ingestion->path);

        if (! is_file($path)) {
            throw new RuntimeException('Il file temporaneo non è disponibile.');
        }

        $mimeType = $this->realMimeType($path, $ingestion->original_filename);

        if (! in_array($mimeType, config('knowledge.allowed_mime_types', []), true)) {
            throw new RuntimeException('Il contenuto reale del file non è supportato.');
        }

        $text = match ($mimeType) {
            'text/plain', 'text/markdown' => $this->extractPlainText($path),
            'application/pdf' => $this->extractPdf($path),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($path),
            'image/jpeg', 'image/png', 'image/webp' => $this->extractImage($path, $mimeType),
            default => throw new RuntimeException('Formato non supportato.'),
        };

        return $this->normalize($text);
    }

    private function extractPlainText(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Non è stato possibile leggere il file di testo.');
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new RuntimeException('Il file di testo deve essere codificato in UTF-8.');
        }

        return $contents;
    }

    private function extractPdf(string $path): string
    {
        $document = (new Parser)->parseFile($path);
        $pages = $document->getPages();

        if (count($pages) > (int) config('knowledge.max_pdf_pages', 100)) {
            throw new RuntimeException('Il PDF supera il numero massimo di pagine consentito.');
        }

        $text = $document->getText();

        if (mb_strlen(trim($text)) >= 50) {
            return $text;
        }

        return $this->extractScannedPdf($path, count($pages));
    }

    private function extractScannedPdf(string $path, int $pageCount): string
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('Il PDF sembra scansionato, ma il supporto immagini PDF non è disponibile.');
        }

        $maxPages = (int) config('knowledge.max_vision_pages', 20);

        if ($pageCount > $maxPages) {
            throw new RuntimeException("Il PDF scansionato supera il limite di {$maxPages} pagine.");
        }

        $document = new Imagick;
        $document->setResolution(144, 144);
        $document->readImage($path);
        $parts = [];

        foreach ($document as $index => $page) {
            $page->setImageFormat('jpeg');
            $page->setImageCompressionQuality(82);
            $parts[] = sprintf(
                "[Pagina %d]\n%s",
                $index + 1,
                $this->client->extractTextFromImage('image/jpeg', $page->getImageBlob()),
            );
        }

        $document->clear();

        return implode("\n\n", $parts);
    }

    private function extractDocx(string $path): string
    {
        $this->validateDocxArchive($path);
        $document = IOFactory::load($path, 'Word2007');
        $parts = [];

        foreach ($document->getSections() as $section) {
            $this->collectElementText($section, $parts);
        }

        return implode("\n", array_filter($parts));
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function collectElementText(object $element, array &$parts): void
    {
        if (method_exists($element, 'getText')) {
            $text = $element->getText();

            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        foreach (['getElements', 'getRows', 'getCells'] as $method) {
            if (! method_exists($element, $method)) {
                continue;
            }

            foreach ($element->{$method}() as $child) {
                if (is_object($child)) {
                    $this->collectElementText($child, $parts);
                }
            }
        }
    }

    private function extractImage(string $path, string $mimeType): string
    {
        $dimensions = getimagesize($path);

        if ($dimensions === false) {
            throw new RuntimeException('L’immagine non è valida.');
        }

        if (($dimensions[0] * $dimensions[1]) > (int) config('knowledge.max_image_pixels', 40000000)) {
            throw new RuntimeException('L’immagine supera il limite massimo di pixel.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Non è stato possibile leggere l’immagine.');
        }

        return $this->client->extractTextFromImage($mimeType, $contents);
    }

    private function validateDocxArchive(string $path): void
    {
        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            throw new RuntimeException('Il documento DOCX non è valido.');
        }

        $totalBytes = 0;
        $maxBytes = (int) config('knowledge.max_extracted_characters', 500000) * 10;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);

            if (! is_array($entry)) {
                continue;
            }

            $size = (int) ($entry['size'] ?? 0);
            $compressedSize = max(1, (int) ($entry['comp_size'] ?? 1));
            $totalBytes += $size;

            if (($size / $compressedSize) > 200 || $totalBytes > $maxBytes) {
                $archive->close();

                throw new RuntimeException('Il documento compresso supera i limiti di sicurezza.');
            }
        }

        $archive->close();
    }

    private function realMimeType(string $path, ?string $filename): string
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! is_string($detected)) {
            throw new RuntimeException('Non è stato possibile verificare il tipo del file.');
        }

        if ($detected === 'application/zip' && str_ends_with(mb_strtolower((string) $filename), '.docx')) {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        if ($detected === 'text/plain' && str_ends_with(mb_strtolower((string) $filename), '.md')) {
            return 'text/markdown';
        }

        return $detected;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('Non è stato trovato contenuto testuale utilizzabile.');
        }

        $limit = (int) config('knowledge.max_extracted_characters', 500000);

        if (mb_strlen($text) > $limit) {
            throw new RuntimeException("Il testo estratto supera il limite di {$limit} caratteri.");
        }

        return $text;
    }
}
