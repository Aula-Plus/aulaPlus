<?php

namespace App\Services\Materials;

use App\Enums\MaterialCatalogStatus;
use App\Enums\MaterialSource;
use App\Enums\MaterialType;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\Material;
use App\Models\User;
use App\Notifications\MaterialPublishedNotification;
use App\Notifications\MaterialRemovedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Publishing to and taking out of the institutional catalog (screen 19,
 * P19-DEC-04 / PA-109). The school has no approval switch yet (screen 25), so
 * a publication takes effect on save and Psicopedagogía and Dirección get a
 * notice; either of them can take it out. Every step records who and when.
 */
class MaterialCatalog
{
    /**
     * Publish an uploaded file. The caller confirmed «Revisé que no tenga
     * datos de alumnos» (an uploaded file cannot be cleaned automatically);
     * the confirmation is stored with its author and time.
     */
    public function publishUpload(Material $material, User $user): Material
    {
        $material->forceFill([
            'no_student_data_confirmed_at' => now(),
            'no_student_data_confirmed_by_id' => $user->id,
        ]);

        return $this->publish($material, $user);
    }

    /**
     * Publish a confirmed assessment: its catalog copy is a snapshot of the
     * content with every student mark removed (screen 19 red line). Publishing
     * it again after it was taken out refreshes the snapshot.
     */
    public function publishAssessment(Assessment $assessment, User $user): Material
    {
        return DB::transaction(function () use ($assessment, $user): Material {
            $assessment->loadMissing('group', 'curricularItems:id');
            $cleaner = new StudentMarkCleaner($assessment->group);
            $title = $cleaner->cleanText((string) ($assessment->purpose ?: 'Evaluación'));

            $material = Material::query()->lockForUpdate()->firstOrNew(['assessment_id' => $assessment->id]);
            $material->fill([
                'owner_id' => $assessment->teacher_id,
                'group_id' => $assessment->group_id,
                'subject_id' => $assessment->subject_id,
                'school_grade_id' => $assessment->group->school_grade_id,
                'source' => MaterialSource::Assessment,
                'title' => mb_substr($title, 0, 200),
                'type' => MaterialType::Assessment,
                'content' => $cleaner->clean($assessment->content),
            ]);

            $material = $this->publish($material, $user);
            $material->curricularItems()->sync($assessment->curricularItems->pluck('id'));

            return $material;
        });
    }

    /**
     * Take a material out of the catalog. Its owner is told («Sacaron del
     * catálogo tu material»), with the reason when the remover wrote one —
     * unless the owner took it out themselves.
     */
    public function removeFromCatalog(Material $material, User $user, ?string $reason = null): Material
    {
        $material->forceFill([
            'catalog_status' => MaterialCatalogStatus::Removed,
            'removed_at' => now(),
            'removed_by_id' => $user->id,
            'removed_reason' => $reason,
        ])->save();

        if ($material->owner_id !== $user->id) {
            $owner = User::query()
                ->where('school_id', $material->school_id)
                ->whereKey($material->owner_id)
                ->whereNull('disabled_at')
                ->first();
            $owner?->notify(new MaterialRemovedNotification($material, $reason));
        }

        return $material;
    }

    protected function publish(Material $material, User $user): Material
    {
        $wasPublished = $material->exists && $material->isPublished();

        $material->forceFill([
            'catalog_status' => MaterialCatalogStatus::Published,
            'published_at' => $wasPublished ? $material->published_at : now(),
            'published_by_id' => $wasPublished ? $material->published_by_id : $user->id,
            'removed_at' => null,
            'removed_by_id' => null,
            'removed_reason' => null,
        ])->save();

        if (! $wasPublished) {
            $recipients = User::query()
                ->where('school_id', $material->school_id)
                ->whereKeyNot($user->id)
                ->whereNull('disabled_at')
                ->role(Role::schoolWideValues())
                ->get();

            Notification::send($recipients, new MaterialPublishedNotification($material));
        }

        return $material;
    }
}
