<?php

namespace Database\Seeders;

use App\Enums\AnepPrimaryBody;
use App\Enums\AnepSecondaryBody;
use App\Enums\CurricularItemType;
use App\Models\CurricularCatalog;
use App\Models\CurricularFramework;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Development/demo data for the 3 real pilot schools. Not run in production
 * (see DatabaseSeeder — no shared/demo credentials, security rule #1).
 */
class PilotSchoolsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CurricularFrameworkSeeder::class);

        $escuelaIntegral = $this->seedSchool(
            name: 'Escuela Integral',
            levelsOffered: ['Inicial', 'Primaria', 'Secundaria'],
            instructionLanguages: ['Español'],
            anepPrimaryBody: AnepPrimaryBody::Dgeip,
            anepSecondaryBody: AnepSecondaryBody::NotApplicable,
            frameworkNames: ['ANEP EBI', 'ANEP Bachillerato'],
        );

        $ivyThomas = $this->seedSchool(
            name: 'Ivy Thomas Memorial School',
            levelsOffered: ['Primaria', 'Secundaria'],
            instructionLanguages: ['Español', 'Inglés'],
            anepPrimaryBody: AnepPrimaryBody::Dgeip,
            anepSecondaryBody: AnepSecondaryBody::Dges,
            frameworkNames: ['Cambridge Primary', 'Cambridge Lower Secondary'],
        );

        $stPatricks = $this->seedSchool(
            name: "St. Patrick's College",
            levelsOffered: ['Primaria', 'Secundaria'],
            instructionLanguages: ['Español', 'Inglés'],
            anepPrimaryBody: AnepPrimaryBody::Dgeip,
            anepSecondaryBody: AnepSecondaryBody::Dges,
            frameworkNames: ['IB MYP', 'IB DP'],
        );

        // One catalog with a 3-level item hierarchy, under St. Patrick's IB DP
        // framework, to demonstrate the curricular tree.
        $this->seedCurricularTree(CurricularFramework::query()->where('name', 'IB DP')->firstOrFail());

        unset($escuelaIntegral, $ivyThomas, $stPatricks);
    }

    /**
     * @param  list<string>  $levelsOffered
     * @param  list<string>  $instructionLanguages
     * @param  list<string>  $frameworkNames
     */
    protected function seedSchool(
        string $name,
        array $levelsOffered,
        array $instructionLanguages,
        AnepPrimaryBody $anepPrimaryBody,
        AnepSecondaryBody $anepSecondaryBody,
        array $frameworkNames,
    ): School {
        $school = School::factory()->create([
            'name' => $name,
            'levels_offered' => $levelsOffered,
            'instruction_languages' => $instructionLanguages,
            'anep_authorization_type' => 'habilitado',
            'anep_primary_body' => $anepPrimaryBody,
            'anep_secondary_body' => $anepSecondaryBody,
        ]);

        foreach ($frameworkNames as $frameworkName) {
            $framework = CurricularFramework::query()->where('name', $frameworkName)->firstOrFail();
            $school->curricularFrameworks()->attach($framework, ['active' => true]);
        }

        return Tenancy::forSchool($school, function () use ($school, $name) {
            $teacher = User::factory()->forSchool($school)->teacher()->create([
                'name' => "Docente {$name}",
            ]);
            User::factory()->forSchool($school)->psychopedagogue()->create([
                'name' => "Psicopedagogo/a {$name}",
            ]);
            User::factory()->forSchool($school)->director()->create([
                'name' => "Director/a {$name}",
            ]);

            $schoolYear = (int) now()->year;

            foreach (range(1, random_int(2, 3)) as $i) {
                $group = Group::factory()->create([
                    'school_id' => $school->id,
                    'name' => "{$i}° A",
                    'school_year' => $schoolYear,
                ]);
                $group->teachers()->attach($teacher);

                Student::factory()
                    ->count(random_int(15, 20))
                    ->create(['school_id' => $school->id])
                    ->each(fn (Student $student) => $student->groups()->attach($group, ['school_year' => $schoolYear]));
            }

            return $school;
        });
    }

    protected function seedCurricularTree(CurricularFramework $framework): void
    {
        $catalog = CurricularCatalog::query()->create([
            'curricular_framework_id' => $framework->id,
            'name' => "{$framework->name} — catálogo piloto",
            'valid_from' => now()->startOfYear(),
        ]);

        $competency = CurricularItem::query()->create([
            'curricular_catalog_id' => $catalog->id,
            'parent_id' => null,
            'type' => CurricularItemType::GeneralCompetency,
            'code' => 'GC-1',
            'name' => 'Pensamiento crítico',
        ]);

        $subject = CurricularItem::query()->create([
            'curricular_catalog_id' => $catalog->id,
            'parent_id' => $competency->id,
            'type' => CurricularItemType::Subject,
            'code' => 'SUB-1',
            'name' => 'Matemática',
        ]);

        CurricularItem::query()->create([
            'curricular_catalog_id' => $catalog->id,
            'parent_id' => $subject->id,
            'type' => CurricularItemType::Strand,
            'code' => 'STR-1',
            'name' => 'Álgebra',
        ]);

        CurricularItem::query()->create([
            'curricular_catalog_id' => $catalog->id,
            'parent_id' => $subject->id,
            'type' => CurricularItemType::Strand,
            'code' => 'STR-2',
            'name' => 'Geometría',
        ]);
    }
}
