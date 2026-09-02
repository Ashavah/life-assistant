<?php

namespace App\Services\Knowledge;

class KnowledgeTextChunker
{
    /**
     * @return array<int, array{reference: string, content: string}>
     */
    public function chunk(string $text): array
    {
        $chunkSize = max(1000, (int) config('knowledge.chunk_characters', 12000));
        $overlap = min($chunkSize - 1, max(0, (int) config('knowledge.chunk_overlap', 500)));
        $length = mb_strlen($text);
        $chunks = [];
        $offset = 0;
        $index = 1;

        while ($offset < $length) {
            $candidate = mb_substr($text, $offset, $chunkSize);
            $isLastChunk = ($offset + $chunkSize) >= $length;

            if (! $isLastChunk) {
                $breakAt = max(
                    mb_strrpos($candidate, "\n\n") ?: 0,
                    mb_strrpos($candidate, '. ') ?: 0,
                );

                if ($breakAt > (int) ($chunkSize * 0.6)) {
                    $candidate = mb_substr($candidate, 0, $breakAt + 1);
                }
            }

            $consumed = max(1, mb_strlen($candidate));
            $candidate = trim($candidate);

            if ($candidate !== '') {
                $chunks[] = [
                    'reference' => "sezione {$index}",
                    'content' => $candidate,
                ];
                $index++;
            }

            $offset += $isLastChunk
                ? $consumed
                : max(1, $consumed - $overlap);
        }

        return $chunks;
    }
}
