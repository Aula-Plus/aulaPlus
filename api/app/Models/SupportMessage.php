<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A support/improvement message from staff to the Aula+ team. Deliberately
 * write-only from the app: users cannot read messages back, and the Aula+ team
 * reads them out-of-band (email + the table). The free text leaves the
 * permission circuit, so the form warns users not to include student data;
 * the content is never logged.
 */
#[Fillable(['user_id', 'kind', 'message', 'role', 'screen'])]
class SupportMessage extends Model
{
    use BelongsToSchool;

    public const KIND_ISSUE = 'issue';

    public const KIND_IMPROVEMENT = 'improvement';

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [self::KIND_ISSUE, self::KIND_IMPROVEMENT];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
