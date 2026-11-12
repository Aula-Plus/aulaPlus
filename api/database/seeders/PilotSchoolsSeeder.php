<?php

namespace Database\Seeders;

use App\Enums\AnepPrimaryBody;
use App\Enums\AnepSecondaryBody;
use App\Enums\AssessmentType;
use App\Enums\CurricularItemType;
use App\Models\Assessment;
use App\Models\CurricularCatalog;
use App\Models\CurricularFramework;
use App\Models\CurricularItem;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\Student;
use App\Models\Subject;
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
        $this->call(OfficialScaleSeeder::class);

        $escuelaIntegral = $this->seedSchool(
            name: 'Escuela Integral',
            emailSlug: 'integral',
            levelsOffered: ['Inicial', 'Primaria', 'Secundaria'],
            instructionLanguages: ['Español'],
            anepPrimaryBody: AnepPrimaryBody::Dgeip,
            anepSecondaryBody: AnepSecondaryBody::NotApplicable,
            frameworkNames: ['ANEP EBI', 'ANEP Bachillerato'],
        );

        $ivyThomas = $this->seedSchool(
            name: 'Ivy Thomas Memorial School',
            emailSlug: 'ivy',
            levelsOffered: ['Primaria', 'Secundaria'],
            instructionLanguages: ['Español', 'Inglés'],
            anepPrimaryBody: AnepPrimaryBody::Dgeip,
            anepSecondaryBody: AnepSecondaryBody::Dges,
            frameworkNames: ['Cambridge Primary', 'Cambridge Lower Secondary'],
        );

        $stPatricks = $this->seedSchool(
            name: "St. Patrick's College",
            emailSlug: 'stpatricks',
            levelsOffered: ['Primaria', 'Secundaria'],
            instructionLanguages: ['Español', 'Inglés'],
            anepPrimaryBody: AnepPrimaryBody::Dgeip,
            anepSecondaryBody: AnepSecondaryBody::Dges,
            frameworkNames: ['IB MYP', 'IB DP'],
        );

        // The example Primaria group of the living document (5.°A), on ANEP
        // EBI, with an assessment ready to be loaded with the rubric scale.
        $this->seedPrimaryGroup($escuelaIntegral, 'integral');

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
        string $emailSlug,
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

        return Tenancy::forSchool($school, function () use ($school, $name, $emailSlug) {
            $teacher = User::factory()->forSchool($school)->teacher()->create([
                'name' => "Docente {$name}",
                'email' => "m{$emailSlug}@gmail.com",
            ]);
            User::factory()->forSchool($school)->psychopedagogue()->create([
                'name' => "Psicopedagogo/a {$name}",
                'email' => "p{$emailSlug}@gmail.com",
            ]);
            User::factory()->forSchool($school)->director()->create([
                'name' => "Director/a {$name}",
                'email' => "d{$emailSlug}@gmail.com",
            ]);

            $schoolYear = (int) now()->year;

            // A teacher-in-group assignment carries a subject (Session 2,
            // Option A), so the pilot teacher is assigned one subject per group.
            $subject = Subject::factory()->create([
                'school_id' => $school->id,
                'name' => 'Matemática',
            ]);

            foreach (range(1, random_int(2, 3)) as $i) {
                $group = Group::factory()->create([
                    'school_id' => $school->id,
                    'name' => "{$i}° A",
                    'school_year' => $schoolYear,
                ]);
                $group->teachers()->attach($teacher, ['subject_id' => $subject->id]);

                Student::factory()
                    ->count(random_int(15, 20))
                    ->create(['school_id' => $school->id])
                    ->each(fn (Student $student) => $student->groups()->attach($group, ['school_year' => $schoolYear]));
            }

            return $school;
        });
    }

    /**
     * 5.°A of Primaria (ClickUp 1dfd3mc4bgg): linked to the ANEP EBI framework,
     * so its results are recorded with the official Primaria scale, plus one
     * assessment with no results yet.
     */
    protected function seedPrimaryGroup(School $school, string $emailSlug): void
    {
        Tenancy::forSchool($school, function () use ($school, $emailSlug): void {
            $teacher = User::query()->where('email', "m{$emailSlug}@gmail.com")->firstOrFail();
            $subject = Subject::query()->where('school_id', $school->id)->where('name', 'Matemática')->firstOrFail();
            $schoolYear = (int) now()->year;

            $group = Group::factory()->create([
                'school_id' => $school->id,
                'name' => '5.°A',
                'level' => 'Primaria',
                'education_level_id' => EducationLevel::query()->where('key', 'primary')->value('id'),
                'school_grade_id' => SchoolGrade::query()->where('key', 'primary_5')->value('id'),
                'school_year' => $schoolYear,
            ]);
            $group->curricularFrameworks()->attach(
                CurricularFramework::query()->where('name', 'ANEP EBI')->firstOrFail(),
            );
            $group->teachers()->attach($teacher, ['subject_id' => $subject->id]);

            Student::factory()
                ->count(18)
                ->create(['school_id' => $school->id])
                ->each(fn (Student $student) => $student->groups()->attach($group, ['school_year' => $schoolYear]));

            Assessment::factory()->create([
                'school_id' => $school->id,
                'group_id' => $group->id,
                'subject_id' => $subject->id,
                'teacher_id' => $teacher->id,
                'type' => AssessmentType::Written,
                'purpose' => 'Escrito de fracciones',
                'administered_at' => now()->toDateString(),
            ]);
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
