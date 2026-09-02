<?php

namespace App\Services;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;

class MarkdownRenderer
{
    /**
     * Converte in HTML il Markdown prodotto dai modelli.
     *
     * Il testo arriva da una fonte non fidata: l'HTML eventualmente presente viene
     * rimosso e i link con schemi pericolosi vengono neutralizzati.
     */
    public static function toHtml(string $markdown): HtmlString
    {
        return new HtmlString(Str::markdown(trim($markdown), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => [
                'soft_break' => "<br>\n",
            ],
            'external_link' => [
                'internal_hosts' => request()->getHost(),
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ], [
            new ExternalLinkExtension,
        ]));
    }
}
