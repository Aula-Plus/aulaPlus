<?php

namespace App\Notifications\Concerns;

use Illuminate\Support\HtmlString;

/**
 * For mail lines that carry user-supplied text: Laravel runs mail lines
 * through CommonMark, so each one goes through literal() and nobody can plant
 * a link, image or heading in the team's inbox.
 */
trait EscapesMailText
{
    /**
     * Encodes every ASCII punctuation character as a numeric HTML reference.
     * CommonMark never reads a character reference as syntax, so the text
     * renders literally (no links, images, HTML, emphasis…), and the plain-text
     * part decodes the references back to what the user typed. Returned as
     * HtmlString so Blade does not encode the references a second time.
     *
     * Laravel's Markdown::withSecuredEncoding() is not used: it only escapes
     * "[", so "\[text](url)" still renders as a link, and it drops attribute
     * escaping for every mail (e.g. action-button URLs).
     */
    private static function literal(string $text): HtmlString
    {
        return new HtmlString(preg_replace_callback(
            '/[!-\/:-@\[-`{-~]/',
            fn (array $match) => '&#'.ord($match[0]).';',
            $text,
        ));
    }
}
