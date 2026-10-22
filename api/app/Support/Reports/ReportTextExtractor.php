<?php

namespace App\Support\Reports;

use App\Support\Pdf\BoundedPdfParser;
use DOMDocument;
use DOMNode;
use RuntimeException;
use Smalot\PdfParser\Config;
use ZipArchive;

/**
 * Turns an uploaded report (PDF or Word .docx) into a list of page texts, so
 * each AI proposal can cite page and fragment (ClickUp 86e3jujgx). A .docx has
 * no fixed pages: it is split at explicit page breaks, so a document without
 * any is a single "page".
 *
 * Hardened like the syllabus extraction: the PDF decode is bounded (a few KB of
 * crafted Flate data must not exhaust the worker's memory), and a .docx is
 * refused when its XML would inflate past a cap. Failures throw
 * RuntimeException with a generic message — never document content.
 */
class ReportTextExtractor
{
    public const MAX_PAGES = 200;

    /** Cap on the text of the whole report: bounds the prompt and the row. */
    public const MAX_TEXT_LENGTH = 200_000;

    public const MAX_DECODED_STREAM_BYTES = 16 * 1024 * 1024;

    public const MAX_DECODED_TOTAL_BYTES = 64 * 1024 * 1024;

    public const MAX_DOCX_XML_BYTES = 16 * 1024 * 1024;

    /**
     * @return list<string> one text per page (empty string for a page without text); [] when the report has no text at all
     *
     * @throws RuntimeException when the file can't be read
     */
    public function extract(string $content, string $format): array
    {
        $pages = match ($format) {
            'pdf' => $this->fromPdf($content),
            'docx' => $this->fromDocx($content),
            default => throw new RuntimeException('Unsupported format.'),
        };

        $pages = array_map(fn (string $page): string => $this->clean($page), $pages);

        // Keep empty pages so list positions stay the real page numbers.
        return implode('', $pages) === '' ? [] : $this->limit(array_values($pages));
    }

    /**
     * @return list<string>
     */
    protected function fromPdf(string $content): array
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(self::MAX_DECODED_STREAM_BYTES);
        $parser = new BoundedPdfParser($config, self::MAX_DECODED_TOTAL_BYTES);

        $document = $parser->parseContent($content);
        $pages = [];

        foreach (array_slice($document->getPages(), 0, self::MAX_PAGES) as $page) {
            $pages[] = $page->getText();
        }

        if ($parser->budgetExceeded()) {
            throw new RuntimeException('PDF decode budget exceeded.');
        }

        return $pages;
    }

    /**
     * @return list<string>
     */
    protected function fromDocx(string $content): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Zip support is not available.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'docx');

        try {
            file_put_contents($tmp, $content);
            $zip = new ZipArchive;

            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('Not a Word document.');
            }

            $stat = $zip->statName('word/document.xml');

            if ($stat === false || $stat['size'] > static::MAX_DOCX_XML_BYTES) {
                $zip->close();
                throw new RuntimeException('Word document unreadable or too large.');
            }

            // The size above is what the archive declares, and a crafted file
            // can lie about it: read at most one byte past the cap, so a zip
            // bomb is never inflated in memory, and refuse if it got there.
            $xml = $zip->getFromName('word/document.xml', static::MAX_DOCX_XML_BYTES + 1);
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        if (! is_string($xml) || strlen($xml) > static::MAX_DOCX_XML_BYTES) {
            throw new RuntimeException('Word document unreadable or too large.');
        }

        // A real document.xml never carries a DTD: refusing one rules out
        // entity tricks (external entities, "billion laughs" expansion).
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('Word document unreadable.');
        }

        $dom = new DOMDocument;

        // LIBXML_NONET: never fetch anything from the network while parsing.
        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('Word document unreadable.');
        }

        $pages = [''];

        foreach ($dom->getElementsByTagNameNS('*', 'p') as $paragraph) {
            // A paragraph nested in another (a text box) is read with its
            // outer paragraph: reading it again would duplicate its text.
            if ($this->hasParagraphAncestor($paragraph)) {
                continue;
            }

            $line = '';

            foreach ($paragraph->getElementsByTagNameNS('*', '*') as $node) {
                if ($node->localName === 't') {
                    $line .= $node->textContent;
                } elseif ($node->localName === 'tab') {
                    $line .= ' ';
                } elseif ($node->localName === 'br' && $node->getAttributeNS($node->namespaceURI, 'type') === 'page') {
                    // What came before the break, in this same paragraph,
                    // belongs to the page that ends here.
                    $pages[count($pages) - 1] .= $line."\n";
                    $pages[] = '';
                    $line = '';
                }
            }

            $pages[count($pages) - 1] .= $line."\n";
        }

        return array_slice($pages, 0, self::MAX_PAGES);
    }

    protected function hasParagraphAncestor(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->localName === 'p') {
                return true;
            }
        }

        return false;
    }

    /**
     * Valid UTF-8 without control characters: PostgreSQL rejects NUL in text.
     * Composed (NFC): PDFs often carry accents as separate combining marks
     * ("i" + "´"), which the anonymizer would not see as the same letter.
     */
    protected function clean(string $text): string
    {
        $text = ReportAnonymizer::normalize(mb_scrub($text, 'UTF-8'));
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }

    /**
     * @param  list<string>  $pages
     * @return list<string>
     */
    protected function limit(array $pages): array
    {
        $remaining = self::MAX_TEXT_LENGTH;
        $out = [];

        foreach ($pages as $page) {
            if ($remaining <= 0) {
                break;
            }

            $out[] = mb_substr($page, 0, $remaining);
            $remaining -= mb_strlen($page);
        }

        return $out;
    }
}
