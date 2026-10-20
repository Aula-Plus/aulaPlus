<?php

namespace App\Support\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\RawData\FilterHelper;
use Smalot\PdfParser\RawData\RawDataParser;

/**
 * smalot/pdfparser with a cap on the TOTAL bytes it may decode for one
 * document. The library inflates every stream up front and keeps them all in
 * memory, and its own limit (Config::setDecodeMemoryLimit) is per stream: an
 * upload with many small, highly compressed streams could still exhaust the
 * worker's memory — an uncatchable fatal error. Once the budget is spent, the
 * remaining streams are left undecoded and budgetExceeded() reports it, so
 * the caller discards the (incomplete) text instead of using it.
 */
class BoundedPdfParser extends Parser
{
    private DecodeBudgetFilterHelper $filters;

    public function __construct(Config $config, int $totalDecodeBytes)
    {
        parent::__construct([], $config);

        $this->filters = new DecodeBudgetFilterHelper($totalDecodeBytes);

        // RawDataParser builds its own FilterHelper with no injection point.
        $this->rawDataParser = new class([], $config, $this->filters) extends RawDataParser
        {
            public function __construct(array $cfg, Config $config, FilterHelper $filters)
            {
                parent::__construct($cfg, $config);
                $this->filterHelper = $filters;
            }
        };
    }

    public function budgetExceeded(): bool
    {
        return $this->filters->exceeded();
    }
}
