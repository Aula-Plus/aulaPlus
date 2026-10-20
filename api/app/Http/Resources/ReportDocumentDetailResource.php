<?php

namespace App\Http\Resources;

use App\Models\ReportDocument;
use App\Support\Reports\AiReportSwitch;
use Illuminate\Http\Request;

/**
 * A report with its anonymized text and proposals — only for psychopedagogy and
 * direction (ReportDocumentPolicy::viewContent). The original extracted text
 * is never returned: review happens on the anonymized text, which is also the
 * only text that can leave the system.
 *
 * @mixin ReportDocument
 */
class ReportDocumentDetailResource extends ReportDocumentResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'anonymized_pages' => $this->anonymized_pages,
            'ai_available' => AiReportSwitch::allowedFor($this->resource, $this->school),
            'proposals' => AccommodationProposalResource::collection(
                $this->proposals()->orderBy('id')->get(),
            ),
        ];
    }
}
