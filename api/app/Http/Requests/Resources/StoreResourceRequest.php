<?php

namespace App\Http\Requests\Resources;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A resource's name (school or group, screen 23 / PA-085). Authorization is in
 * the controller (SchoolResourcePolicy / GroupPolicy::manageResources).
 */
class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
