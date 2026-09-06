<?php

namespace App\Http\Resources;

use App\Models\AIProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AIProposal
 */
class AIProposalResource extends JsonResource
{
    /**
     * `context_sent` is deliberately NOT exposed: it is an internal audit/debug
     * snapshot, not something the polling frontend needs.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'requested_by_id' => $this->requested_by_id,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'input_parameters' => $this->input_parameters,
            'raw_response' => $this->raw_response,
            'error_message' => $this->error_message,
            'applied_to_id' => $this->applied_to_id,
            'applied_to_type' => $this->applied_to_type,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
