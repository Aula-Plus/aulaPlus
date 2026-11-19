<?php

namespace App\Models;

use App\Enums\CalendarEventType;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A date stored in the Calendar (screen 21). Today only institutional events
 * are created (by Direction; every staff member may see them in the school's
 * full calendar, and each person's own calendar shows the ones whose
 * `audiences` touch their groups — ClickUp 1dfd3mc55zp); `meeting` is reserved
 * for screen 10 and, when it exists, is visible only to the people whose
 * calendar it is linked to (the screen's red line). See CalendarEventPolicy.
 *
 * `all_day` = no time: `start_at` is the school-local midnight of its day.
 */
#[Fillable(['title', 'description', 'start_at', 'end_at', 'all_day', 'location', 'type', 'created_by_id'])]
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    public function calendars(): BelongsToMany
    {
        return $this->belongsToMany(Calendar::class, 'calendar_calendar_event')
            ->withTimestamps();
    }

    /**
     * Who an institutional event concerns (ClickUp 1dfd3mc55zp). No rows =
     * the whole school.
     */
    public function audiences(): HasMany
    {
        return $this->hasMany(CalendarEventAudience::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isInstitutional(): bool
    {
        return $this->type === CalendarEventType::Institutional->value;
    }

    /**
     * Events every staff member of the school may see (only institutional
     * ones). Any other type is private to the calendars it is linked to.
     *
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeInstitutional(Builder $query): void
    {
        $query->where('type', CalendarEventType::Institutional->value);
    }
}
