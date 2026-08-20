<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Database\Factories\CalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A user's personal calendar (1:1). Its events are shared, reusable
 * CalendarEvent records linked through the calendar_calendar_event pivot.
 */
#[Fillable(['user_id'])]
class Calendar extends Model
{
    /** @use HasFactory<CalendarFactory> */
    use BelongsToSchool, HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(CalendarEvent::class, 'calendar_calendar_event')
            ->withTimestamps();
    }
}
