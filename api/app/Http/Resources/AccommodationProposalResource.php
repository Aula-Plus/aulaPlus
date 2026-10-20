<?php

namespace App\Http\Resources;

use App\Models\AccommodationProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccommodationProposal
 */
class AccommodationProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_document_id' => $this->report_document_id,
            'type' => $this->type,
            'category' => $this->category->value,
            'description' => $this->description,
            'focus_area' => $this->focus_area,
            // explicit (stated in the report) · inferred (deduced by the AI) · manual
            'basis' => $this->basis->value,
            'source_page' => $this->source_page,
            'source_fragment' => $this->source_fragment,
            'status' => $this->status->value,
            'accommodation_id' => $this->accommodation_id,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
