<?php

namespace App\Http\Requests;

use App\Enums\CalendarItemType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/calendar — a window of school-local days (inclusive) and the
 * types to include (all seven when omitted), and whether institutional
 * events are the caller's own (`scope=mine`, default) or the school's full
 * calendar (`scope=school`). The window is capped so a month
 * view with its leading/trailing weeks fits but nobody pulls the whole year.
 */
class CalendarFeedRequest extends FormRequest
{
    public const MAX_DAYS = 62;

    public function authorize(): bool
    {
        // Every staff member has a Calendar; what each one sees is decided
        // per source in CalendarFeed.
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, \Closure $fail): void {
                $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('from'));
                $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $value);
                if ($from !== false && $to !== false && $from->diffInDays($to) > self::MAX_DAYS) {
                    $fail('The window may span at most '.self::MAX_DAYS.' days.');
                }
            }],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', Rule::enum(CalendarItemType::class)],
            // «Ver todo el calendario del colegio» (ClickUp 1dfd3mc55zp): every
            // institutional event, not only the ones that concern the caller.
            'scope' => ['sometimes', Rule::in(['mine', 'school'])],
        ];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('from'));
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('to'));
    }

    public function wholeSchool(): bool
    {
        return $this->validated('scope') === 'school';
    }

    /**
     * @return list<CalendarItemType>
     */
    public function types(): array
    {
        $types = $this->validated('types');

        return $types === null
            ? CalendarItemType::cases()
            : array_values(array_map(fn (string $type) => CalendarItemType::from($type), array_unique($types)));
    }
}
