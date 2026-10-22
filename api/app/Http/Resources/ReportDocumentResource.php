<?php

namespace App\Http\Resources;

use App\Models\ReportDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student report as the current user may see it (ClickUp 86e3jujgx). A
 * teacher gets the file's name, format, size and visibility only; psychopedagogy
 * and direction also get its processing state. The anonymized text and the
 * proposals travel in {@see ReportDocumentDetailResource}, never here.
 *
 * @mixin ReportDocument
 */
class ReportDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'name' => $this->original_name,
            'format' => $this->format,
            'size' => $this->size,
            'visibility' => $this->visibility->value,
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];

        if (! $request->user()->can('viewContent', $this->resource)) {
            return $data;
        }

        return [
            ...$data,
            'status' => $this->status->value,
            'use_ai' => $this->use_ai,
            'send_confirmed_at' => $this->send_confirmed_at?->toIso8601String(),
            'error_message' => $this->error_message,
        ];
    }
}
