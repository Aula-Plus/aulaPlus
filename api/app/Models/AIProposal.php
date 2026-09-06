<?php

namespace App\Models;

use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\AIProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An AI-assisted draft of a planning entity (docs/prompts/
 * 05-asistente-ia-docente.md). Tenant-scoped and Auditable.
 *
 * Authorship is modelled with an explicit `requested_by_id` set at create
 * time, NOT the TracksAuthorship `created_by_id` pattern — same choice as
 * AnnualPlan (explicit `teacher_id`): only the requester may apply or discard,
 * and the applied entity's own authorship points at whoever applies it, which
 * may differ from who requested the draft.
 */
#[Fillable([
    'group_id',
    'requested_by_id',
    'type',
    'input_parameters',
    'context_sent',
    'raw_response',
    'status',
    'error_message',
    'applied_to_id',
    'applied_to_type',
])]
class AIProposal extends Model
{
    /** @use HasFactory<AIProposalFactory> */
    use Auditable, BelongsToSchool, HasFactory;

    protected $table = 'ai_proposals';

    /**
     * Large blobs kept out of the audit diff: the context snapshot and the raw
     * model response are big and (for context_sent) hold aggregated student
     * data — recording only `['changed' => true]` is enough (CLAUDE.md rules
     * 2 and 11). Same pattern as Accommodation::$auditableExcludeFromDiff.
     */
    public static array $auditableExcludeFromDiff = ['context_sent', 'raw_response'];

    protected function casts(): array
    {
        return [
            'type' => AIProposalType::class,
            'status' => AIProposalStatus::class,
            'input_parameters' => 'array',
            'context_sent' => 'array',
            'raw_response' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /**
     * The real entity created when this proposal was applied (AnnualPlan,
     * Unit, ClassSession or Assessment), or null while it is still a draft.
     */
    public function appliedTo(): MorphTo
    {
        return $this->morphTo();
    }
}
