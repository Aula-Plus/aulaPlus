<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Screen 23 › «Oferta curricular» and «Planificadores» (ClickUp 1dfd3mc4bgf).
 *
 * - `curricular_frameworks.planner_family`: which set of planner fields a
 *   framework brings (App\Enums\PlannerFamily). Data, not code (P23-DEC-03):
 *   a new framework is a row. Null = only the common fields.
 * - `curricular_offerings`: the programs each group-subject follows. One row
 *   per (group, subject, framework), so a group-subject can follow several at
 *   once (ANEP + Cambridge) without duplicating groups (P23-DEC-02).
 * - `planner_field_definitions`: global catalog of the unit-planner fields —
 *   the common base plus what each external program asks for
 *   (`required_by_program`: renameable, never removable). Seeded here so it
 *   exists in every environment, like `education_levels`.
 * - `school_planner_fields`: a school's configuration. Either an override of
 *   a catalog field (its name in the school, shown or hidden) or a field of
 *   the school's own (name, type, options, for every framework or only one).
 *   Changes apply to new units (screen 23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricular_frameworks', function (Blueprint $table) {
            $table->string('planner_family')->nullable()->after('name');
        });

        foreach (self::FRAMEWORK_FAMILIES as $name => $family) {
            DB::table('curricular_frameworks')->where('name', $name)->update(['planner_family' => $family]);
        }

        Schema::create('curricular_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curricular_framework_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'subject_id', 'curricular_framework_id']);
            $table->index(['school_id', 'group_id', 'subject_id']);
        });

        Schema::create('planner_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('family');
            $table->string('key');
            $table->string('official_name');
            $table->string('type');
            $table->json('options')->nullable();
            $table->boolean('required_by_program')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['family', 'key']);
        });

        Schema::create('school_planner_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // Set: an override of a catalog field. Null: a field of the school's own.
            $table->foreignId('planner_field_definition_id')->nullable()->constrained()->cascadeOnDelete();
            // Own fields only: null = every framework, else one PlannerFamily.
            $table->string('family')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->json('options')->nullable();
            $table->boolean('shown')->default(true);
            $table->boolean('required_by_school')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'planner_field_definition_id']);
        });

        $now = now();
        $rows = [];
        foreach (self::DEFINITIONS as $family => $fields) {
            foreach ($fields as $index => [$key, $name, $type, $options]) {
                $rows[] = [
                    'family' => $family,
                    'key' => $key,
                    'official_name' => $name,
                    'type' => $type,
                    'options' => $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE),
                    'required_by_program' => $family !== 'common',
                    'position' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('planner_field_definitions')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('school_planner_fields');
        Schema::dropIfExists('planner_field_definitions');
        Schema::dropIfExists('curricular_offerings');

        Schema::table('curricular_frameworks', function (Blueprint $table) {
            $table->dropColumn('planner_family');
        });
    }

    /** Keep in sync with CurricularFrameworkSeeder::FAMILIES (fresh installs seed after migrating). */
    private const FRAMEWORK_FAMILIES = [
        'ANEP EBI' => 'anep',
        'ANEP Bachillerato' => 'anep',
        'Cambridge Primary' => 'cambridge',
        'Cambridge Lower Secondary' => 'cambridge',
        'IB PYP' => 'ib_pyp',
        'IB MYP' => 'ib_myp',
    ];

    /**
     * Official names are catalog content (like curricular item names), in the
     * language the program uses in Uruguay. [key, official name, type, options].
     */
    private const DEFINITIONS = [
        'common' => [
            ['duration_hours', 'Duración en horas', 'number', null],
            ['contents', 'Contenidos', 'long_text', null],
            ['problem_question', 'Pregunta problematizadora', 'short_text', null],
            ['planned_assessment', 'Evaluación prevista', 'list', [
                'Escrito', 'Trabajo práctico', 'Proyecto', 'Oral', 'Portafolio', 'Otro', 'Sin evaluación sumativa',
            ]],
            ['achievement_criteria', 'Criterios de logro', 'long_text', null],
            ['resources', 'Recursos disponibles', 'automatic', null],
            ['organization_strategies', 'Organización del trabajo y estrategias', 'long_text', null],
            ['differentiation', 'Diferenciación', 'automatic', null],
            ['teacher_notes', 'Notas del docente', 'long_text', null],
        ],
        'ib_myp' => [
            ['key_concept', 'Concepto clave', 'list', [
                'Cambio', 'Comunicación', 'Comunidades', 'Conexiones', 'Creatividad', 'Cultura', 'Desarrollo',
                'Estética', 'Forma', 'Identidad', 'Interacciones globales', 'Lógica', 'Perspectiva',
                'Relaciones', 'Sistemas', 'Tiempo, lugar y espacio',
            ]],
            ['related_concepts', 'Conceptos relacionados', 'short_text', null],
            ['global_context', 'Contexto global', 'list', [
                'Identidades y relaciones', 'Orientación en el espacio y el tiempo', 'Expresión personal y cultural',
                'Innovación científica y técnica', 'Globalización y sustentabilidad', 'Equidad y desarrollo',
            ]],
            ['statement_of_inquiry', 'Enunciado de indagación', 'long_text', null],
            ['inquiry_questions', 'Preguntas de indagación', 'long_text', null],
            ['objectives_criteria', 'Objetivos (criterios A a D)', 'multiple_choice', ['A', 'B', 'C', 'D']],
            ['summative_assessment', 'Evaluación sumativa', 'long_text', null],
            ['approaches_to_learning', 'Enfoques del aprendizaje', 'long_text', null],
            ['reflection_before', 'Reflexión: antes de dar la unidad', 'long_text', null],
            ['reflection_during', 'Reflexión: durante la unidad', 'long_text', null],
            ['reflection_after', 'Reflexión: después de la unidad', 'long_text', null],
        ],
        'ib_pyp' => [
            ['transdisciplinary_theme', 'Tema transdisciplinario', 'list', [
                'Quiénes somos', 'Dónde nos encontramos en el tiempo y el espacio', 'Cómo nos expresamos',
                'Cómo funciona el mundo', 'Cómo nos organizamos', 'Cómo compartimos el planeta',
            ]],
            ['central_idea', 'Idea central', 'long_text', null],
            ['lines_of_inquiry', 'Líneas de indagación', 'long_text', null],
        ],
        'cambridge' => [
            ['strand', 'Eje', 'short_text', null],
        ],
        'anep' => [
            ['mcn_priority_competencies', 'Competencias del MCN priorizadas en el año', 'multiple_choice', [
                'Comunicación', 'Pensamiento creativo', 'Pensamiento crítico', 'Pensamiento científico',
                'Pensamiento computacional', 'Metacognitiva', 'Intrapersonal', 'Iniciativa y orientación a la acción',
                'Relacionamiento con los otros', 'Ciudadanía local, global y digital',
            ]],
        ],
    ];
};
