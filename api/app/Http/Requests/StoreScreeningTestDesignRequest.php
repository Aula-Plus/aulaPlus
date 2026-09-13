<?php

namespace App\Http\Requests;

use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /screening-test-types/{type}/designs. Authorization delegates
 * to ScreeningTestDesignPolicy::create (psychopedagogy only, same school as
 * the route type). `cutoff_high` must be >= `cutoff_low` so the yellow band is
 * well-formed.
 */
class StoreScreeningTestDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [ScreeningTestDesign::class, $this->type()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cutoff_low' => ['required', 'numeric'],
            'cutoff_high' => ['required', 'numeric', 'gte:cutoff_low'],
            'meaning_red' => ['required', 'string'],
            'meaning_yellow' => ['required', 'string'],
            'meaning_green' => ['required', 'string'],
        ];
    }

    protected function type(): ScreeningTestType
    {
        /** @var ScreeningTestType */
        return $this->route('type');
    }
}
