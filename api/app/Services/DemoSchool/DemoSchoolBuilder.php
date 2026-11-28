<?php

namespace App\Services\DemoSchool;

use App\Enums\AccommodationCategory;
use App\Enums\AnepPrimaryBody;
use App\Enums\AnepSecondaryBody;
use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\Barrier;
use App\Models\CurricularFramework;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\School;
use App\Models\SchoolGrade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolYears\SchoolYearCalendar;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds a test school for a leadership walkthrough (ClickUp 1dfd3mc5g1p):
 * its structure, fictitious students and one account per role.
 *
 * Every student name is made up here from two short lists — never real data
 * (Eitán, 26/11: «Sin datos reales de alumnos hasta que yo lo diga»). No
 * factory or Faker is used, so it also runs where dev dependencies are not
 * installed (staging).
 *
 * Passwords are random and only returned to the caller, which shows them once;
 * nothing is hardcoded (security rule #1).
 */
class DemoSchoolBuilder
{
    /**
     * The profiles that can be built. Only the Integral one exists today; Ivy
     * (testing of 15/12) is added here when its structure is known.
     *
     * - `frameworks`: active programs besides the mandatory ANEP regime. «PEP»
     *   is the Spanish name of IB PYP (Programa de la Escuela Primaria).
     * - `grades`: Primaria grades (school_grades.key suffix), one group «N.°A» each.
     * - `teacher_group` / `teacher_subjects`: what the teacher account teaches.
     * - `accommodation_group`: where the fictitious accommodation case goes.
     */
    public const PROFILES = [
        'integral' => [
            'name' => 'Escuela Integral (prueba)',
            'slug' => 'demo-integral',
            'levels_offered' => ['Primaria'],
            'frameworks' => ['IB PYP'],
            'grades' => [1, 2, 3, 4, 5, 6],
            'subjects' => [
                'Lengua',
                'Matemática',
                'Ciencias Naturales',
                'Ciencias Sociales',
                'Educación Artística',
                'Educación Física',
                'Inglés',
            ],
            'students_per_group' => 20,
            'teacher_group' => 5,
            'teacher_subjects' => ['Matemática', 'Lengua'],
            'accommodation_group' => 5,
        ],
    ];

    protected const FIRST_NAMES = [
        'Agustina', 'Bruno', 'Camila', 'Diego', 'Emilia', 'Facundo', 'Guadalupe',
        'Hernán', 'Inés', 'Joaquín', 'Lucía', 'Martín', 'Nahuel', 'Olivia',
        'Pilar', 'Rodrigo', 'Sofía', 'Tomás', 'Valentina', 'Mateo', 'Julieta',
        'Santiago', 'Renata', 'Lautaro', 'Florencia', 'Benjamín', 'Catalina',
        'Thiago', 'Abril', 'Felipe',
    ];

    protected const LAST_NAMES = [
        'Acosta', 'Barreiro', 'Cabrera', 'Duarte', 'Esteves', 'Ferreira',
        'Giménez', 'Herrera', 'Ibarra', 'Larrañaga', 'Medina', 'Núñez',
        'Olivera', 'Pereyra', 'Quintana', 'Rodríguez', 'Silveira', 'Techera',
        'Umpiérrez', 'Viera', 'Sosa', 'Martínez', 'Correa', 'Bentancor',
    ];

    public function __construct(protected SchoolYearCalendar $calendar) {}

    /** @return array<string, mixed> */
    public static function profile(string $key): array
    {
        return self::PROFILES[$key] ?? throw new InvalidArgumentException("Unknown demo profile [{$key}].");
    }

    /**
     * @param  array<string, string>  $emails  role value => email
     * @return array{school: School, accounts: list<array{role: Role, email: string, password: string}>}
     */
    public function build(string $profileKey, array $emails): array
    {
        $profile = self::profile($profileKey);

        $school = School::query()->create([
            'name' => $profile['name'],
            'slug' => $profile['slug'],
            'levels_offered' => $profile['levels_offered'],
            'instruction_languages' => ['Español'],
            'anep_authorization_type' => 'habilitado',
            'anep_primary_body' => AnepPrimaryBody::Dgeip,
            'anep_secondary_body' => AnepSecondaryBody::NotApplicable,
        ]);

        foreach ($profile['frameworks'] as $name) {
            $school->curricularFrameworks()->attach(
                CurricularFramework::query()->where('name', $name)->firstOrFail(),
                ['active' => true],
            );
        }

        // The ANEP regime is loaded for every school, active and mandatory
        // (P23-DEC-01), same as PilotSchoolsSeeder.
        CurricularFramework::query()->where('planner_family', 'anep')->get()
            ->each(fn (CurricularFramework $anep) => $school->curricularFrameworks()
                ->syncWithoutDetaching([$anep->id => ['active' => true, 'mandatory' => true]]));

        return Tenancy::forSchool($school, function () use ($school, $profile, $emails): array {
            $accounts = [];
            $users = [];

            foreach ([Role::Director, Role::Psychopedagogue, Role::Teacher] as $role) {
                $password = Str::password(20);
                $user = User::query()->create([
                    'school_id' => $school->id,
                    'name' => match ($role) {
                        Role::Director => 'Dirección (prueba)',
                        Role::Psychopedagogue => 'Psicopedagogía (prueba)',
                        Role::Teacher => 'Maestra (prueba)',
                    },
                    'email' => $emails[$role->value],
                    'password' => Hash::make($password),
                ]);
                $user->assignRole($role->value);

                $users[$role->value] = $user;
                $accounts[] = ['role' => $role, 'email' => $user->email, 'password' => $password];
            }

            $year = $this->calendar->current($school->id)->year;

            $subjects = collect($profile['subjects'])
                ->mapWithKeys(fn (string $name) => [$name => Subject::query()->create(['name' => $name])]);

            $primary = EducationLevel::query()->where('key', 'primary')->value('id');
            $programs = $school->curricularFrameworks()->get();

            $names = $this->nameSequence();
            $groups = [];

            foreach ($profile['grades'] as $grade) {
                $group = Group::query()->create([
                    'name' => "{$grade}.°A",
                    'level' => 'Primaria',
                    'education_level_id' => $primary,
                    'school_grade_id' => SchoolGrade::query()->where('key', "primary_{$grade}")->value('id'),
                    'school_year' => $year,
                ]);
                $group->curricularFrameworks()->attach($programs);

                foreach (range(1, $profile['students_per_group']) as $i) {
                    $student = Student::query()->create([
                        'full_name' => $names->current(),
                        'birth_date' => sprintf('%d-%02d-%02d', $year - 6 - $grade, ($i % 12) + 1, ($i % 27) + 1),
                        'enrollment_year' => $year,
                        'has_therapeutic_companion' => false,
                    ]);
                    $names->next();
                    $student->groups()->attach($group, ['school_year' => $year]);
                }

                $groups[$grade] = $group;
            }

            $teacherGroup = $groups[$profile['teacher_group']];
            foreach ($profile['teacher_subjects'] as $name) {
                $teacherGroup->teachers()->attach($users[Role::Teacher->value], ['subject_id' => $subjects[$name]->id]);
            }

            $this->seedAccommodationCase($groups[$profile['accommodation_group']], $users[Role::Psychopedagogue->value]);

            return ['school' => $school, 'accounts' => $accounts];
        });
    }

    /**
     * The «caso de ajuste» of the walkthrough: one fictitious student with a
     * learning barrier and an accommodation in force, loaded by psychopedagogy,
     * so a class proposal for that group takes it into account.
     */
    protected function seedAccommodationCase(Group $group, User $psychopedagogue): void
    {
        $student = $group->students()->orderBy('students.id')->firstOrFail();

        $barrier = Barrier::query()->create([
            'student_id' => $student->id,
            'description' => 'Le cuesta sostener la atención en consignas largas con varios pasos.',
            'coping_strategy' => 'Consignas de a una, con un apoyo visual.',
            'active' => true,
            'created_by_id' => $psychopedagogue->id,
        ]);

        $accommodation = Accommodation::query()->create([
            'student_id' => $student->id,
            'type' => 'Tiempo extra en evaluaciones',
            'category' => AccommodationCategory::Access->value,
            'starts_on' => SchoolTime::today()->startOfYear()->toDateString(),
            'active' => true,
            'description' => 'Consignas de a una y tiempo extra en las evaluaciones escritas.',
            'focus_area' => 'Atención',
            'requires_external_approval' => false,
            'created_by_id' => $psychopedagogue->id,
        ]);

        $barrier->accommodations()->attach($accommodation, ['proposed_by_id' => $psychopedagogue->id, 'validated' => true, 'validated_by_id' => $psychopedagogue->id]);
    }

    /**
     * Distinct made-up names: every first name with every last name, walked
     * in a fixed order so the same profile always yields the same students.
     *
     * @return \Generator<int, string>
     */
    protected function nameSequence(): \Generator
    {
        $lastCount = count(self::LAST_NAMES);

        foreach (range(0, count(self::FIRST_NAMES) * $lastCount - 1) as $n) {
            $first = self::FIRST_NAMES[$n % count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[($n * 7 + intdiv($n, count(self::FIRST_NAMES))) % $lastCount];

            yield "{$first} {$last}";
        }
    }
}
