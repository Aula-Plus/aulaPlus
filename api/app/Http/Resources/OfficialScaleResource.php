<?php

namespace App\Http\Resources;

use App\Models\OfficialScale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An official scale with its levels in order and its citation (screen 24 ·
 * «Escalas oficiales»). The visible label is the framework's name; the
 * official document is named in the citation (P24-ESC-01, P24-DEC-06).
 * Assumes `levels`, `curricularFramework` and `educationLevel` are loaded.
 *
 * @mixin OfficialScale
 */
class OfficialScaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'framework' => [
                'id' => $this->curricularFramework->id,
                'name' => $this->curricularFramework->name,
            ],
            'education_level' => $this->educationLevel ? [
                'key' => $this->educationLevel->key,
                'label' => $this->educationLevel->label,
            ] : null,
            'name' => $this->name,
            'issuing_body' => $this->issuing_body,
            'citation' => [
                'document' => $this->citation_document,
                'page' => $this->citation_page,
                'url' => $this->source_url,
            ],
            'levels' => $this->levels->map(fn ($level) => [
                'id' => $level->id,
                'position' => $level->position,
                'name' => $level->name,
                'description' => $level->description,
            ])->values()->all(),
        ];
    }
}
