<?php

namespace App\Support\Pdf;

use RuntimeException;
use Smalot\PdfParser\RawData\FilterHelper;

/**
 * Stream decoder that shares one byte budget across a whole document (see
 * BoundedPdfParser). Throwing makes the library keep that stream undecoded
 * (it ignores decoding errors), so memory stays bounded by budget + file size.
 */
class DecodeBudgetFilterHelper extends FilterHelper
{
    /**
     * Worst-case output/input ratio of the decoders that take no output
     * limit: an LZW code can expand to a ~4 KB dictionary string, a
     * RunLength pair to 128 bytes. Checked before decoding — the blow-up
     * would happen inside the call.
     */
    protected const UNBOUNDED_EXPANSION = [
        'LZWDecode' => 4096,
        'RunLengthDecode' => 64,
    ];

    private int $used = 0;

    private bool $exceeded = false;

    public function __construct(private readonly int $budget) {}

    public function decodeFilter(string $filter, string $data, int $decodeMemoryLimit = 0): string
    {
        $remaining = $this->budget - $this->used;
        $expansion = self::UNBOUNDED_EXPANSION[$filter] ?? 1;

        if ($remaining <= 0 || strlen($data) * $expansion > $remaining) {
            $this->exceeded = true;

            throw new RuntimeException('PDF decode budget exceeded.');
        }

        // Flate honours the limit (0 would mean unlimited, so never pass it).
        $limit = $decodeMemoryLimit > 0 ? min($decodeMemoryLimit, $remaining) : $remaining;
        $decoded = parent::decodeFilter($filter, $data, $limit);

        $this->used += strlen($decoded);
        if ($this->used >= $this->budget) {
            $this->exceeded = true;
        }

        return $decoded;
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }
}
