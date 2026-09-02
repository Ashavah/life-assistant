<?php

return [
    'disk' => env('KNOWLEDGE_DISK', 'local'),
    'queue' => env('KNOWLEDGE_QUEUE', 'knowledge'),
    'ttl_hours' => (int) env('KNOWLEDGE_TTL_HOURS', 24),
    'stalled_after_minutes' => (int) env('KNOWLEDGE_STALLED_AFTER_MINUTES', 15),
    'max_files' => (int) env('KNOWLEDGE_MAX_FILES', 10),
    'max_file_kilobytes' => (int) env('KNOWLEDGE_MAX_FILE_KILOBYTES', 20480),
    'max_text_characters' => (int) env('KNOWLEDGE_MAX_TEXT_CHARACTERS', 200000),
    'max_extracted_characters' => (int) env('KNOWLEDGE_MAX_EXTRACTED_CHARACTERS', 500000),
    'max_pdf_pages' => (int) env('KNOWLEDGE_MAX_PDF_PAGES', 100),
    'max_vision_pages' => (int) env('KNOWLEDGE_MAX_VISION_PAGES', 20),
    'max_image_pixels' => (int) env('KNOWLEDGE_MAX_IMAGE_PIXELS', 40000000),
    'chunk_characters' => (int) env('KNOWLEDGE_CHUNK_CHARACTERS', 12000),
    'chunk_overlap' => (int) env('KNOWLEDGE_CHUNK_OVERLAP', 500),
    'max_candidates' => (int) env('KNOWLEDGE_MAX_CANDIDATES', 150),
    'allowed_mime_types' => [
        'text/plain',
        'text/markdown',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
        'image/webp',
    ],
];
