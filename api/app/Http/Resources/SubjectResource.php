<?php

namespace App\Http\Resources;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subject
 */
class SubjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_code' => $this->short_code,
            'color' => $this->color,
            // Whether the Aula+ team already loaded this subject into the
            // curricular catalog (ClickUp 86e3dt6ag).
            'in_catalog' => $this->curricular_item_id !== null,
            // Program PDF metadata only — the file itself is reached through
            // GET /subjects/{id}/syllabus (temporary URL), never from here.
            'syllabus' => $this->hasSyllabus() ? [
                'name' => $this->syllabus_original_name,
                'size' => $this->syllabus_size,
                'uploaded_at' => $this->syllabus_uploaded_at?->toIso8601String(),
                'text_extracted' => $this->syllabus_text_extracted_at !== null && $this->syllabus_text !== null,
                'can_view' => $user?->can('viewSyllabus', $this->resource) ?? false,
            ] : null,
        ];
    }
}
