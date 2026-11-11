<?php

namespace Database\Seeders;

use App\Models\CurricularFramework;
use App\Models\EducationLevel;
use App\Models\OfficialScale;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The official scales (living document screen 24 · «Escalas oficiales»,
 * ClickUp 1dfd3mc4bgg). Global catalog data, like the curricular frameworks:
 * loaded by the Aula+ team, once per framework, with the citation to the
 * official document and its page — safe to run in every environment
 * (`php artisan db:seed --class=OfficialScaleSeeder`) and idempotent: re-running
 * it updates the texts in place, keyed by `key` and level position.
 *
 * Nothing here is editable from the API or the UI (P24-ESC-01, P24-DEC-03).
 *
 * Texts: ANEP's official descriptors as relevados in the prototype's catalog
 * (ANEP curricular survey, consulted 05/10/2026). Only ANEP scales are loaded:
 * IB and Cambridge have no licensed text yet (P24-DEC-08: sample text only).
 * The official grade bands some ANEP tables print next to each level are NOT
 * loaded: the number next to a Primaria level is the school's own equivalence
 * or the teacher's, never one Aula+ derives (ClickUp answer, 10/11).
 */
class OfficialScaleSeeder extends Seeder
{
    protected const REDE_URL = 'https://www.anep.edu.uy/sites/default/files/images/Archivos/doc-curriculares/generales/2025/ebi/REDE%202024.pdf';

    protected const REEMS_URL = 'https://www.anep.edu.uy/sites/default/files/images/Archivos/doc-curriculares/generales/2025/ems/REEMS%202024%2030_11.pdf';

    /**
     * Levels shared by the two EBI scales (REDE 2024), highest first.
     *
     * @var list<array{0: string, 1: string}>
     */
    protected const REDE_LEVELS = [
        ['Avance destacado', 'El estudiante activa procesos cognitivos complejos al abordar los contenidos programáticos establecidos y logra los desempeños descritos en los criterios de logro correspondientes.'],
        ['Avance significativo', 'El estudiante activa procesos cognitivos al abordar los contenidos programáticos establecidos y logra desempeños parciales de los descritos en los criterios de logro correspondientes.'],
        ['Avance moderado', 'El desempeño del estudiante evidencia logros solamente al abordar contenidos básicos y activar procesos cognitivos simples.'],
        ['Avance escaso', 'El desempeño del estudiante evidencia logros reducidos al abordar contenidos básicos y activar procesos cognitivos simples.'],
        ['Avance mínimo', 'El desempeño del estudiante evidencia dificultades para avanzar en los logros propuestos en la unidad curricular.'],
    ];

    /**
     * @var list<array{0: string, 1: string}>
     */
    protected const REEMS_LEVELS = [
        ['Avance destacado', 'El desempeño del estudiante evidencia haber alcanzado los niveles establecidos en los criterios de logro, asociados a las competencias específicas. Aplica los conocimientos de manera integral, analítica y creativa, en variados contextos y situaciones nuevas. Relaciona los contenidos programáticos entre sí y con otros aprendizajes. Avanza en sus aprendizajes con autonomía e iniciativa.'],
        ['Avance significativo', 'El desempeño del estudiante evidencia haber alcanzado los niveles establecidos en los criterios de logro, asociados a las competencias específicas. Aplica los conocimientos de manera analítica y coherente en contextos diversos y situaciones conocidas. Vincula los contenidos programáticos entre sí. Avanza con autonomía en los aprendizajes esperados.'],
        ['Avance moderado', 'El desempeño del estudiante evidencia haber alcanzado de forma parcial los niveles establecidos en los criterios de logro, asociados a las competencias específicas. Aplica los conocimientos de manera coherente en contextos conocidos y vincula algunos de los contenidos programáticos entre sí. Avanza en sus aprendizajes con apoyo llegando a niveles de autonomía en algunas áreas.'],
        ['Avance escaso', 'El desempeño del estudiante evidencia haber alcanzado parcialmente los niveles establecidos en los criterios de logro, asociados a las competencias específicas. Aplica los conocimientos en contextos conocidos en algunas situaciones. Avanza en sus aprendizajes con orientación y apoyo con escasa autonomía.'],
        ['Avance mínimo', 'El desempeño del estudiante evidencia no haber alcanzado los criterios de logro asociados a las competencias específicas. Aplica algunos conocimientos en contextos conocidos y de manera parcial.'],
    ];

    public function run(): void
    {
        $this->call(CurricularFrameworkSeeder::class);

        $this->scale(
            key: 'anep_ebi_primary',
            framework: 'ANEP EBI',
            level: 'primary',
            name: 'Educación Primaria · Educación Básica Integrada · 1.er y 2.º ciclo',
            issuingBody: 'ANEP',
            document: 'REDE 2024, artículo 21',
            page: '14',
            url: self::REDE_URL,
            levels: self::REDE_LEVELS,
        );

        $this->scale(
            key: 'anep_ebi_lower_secondary',
            framework: 'ANEP EBI',
            level: 'lower_secondary',
            name: 'Ciclo Básico · Educación Básica Integrada · 3.er ciclo',
            issuingBody: 'ANEP',
            document: 'REDE 2024, artículo 38 (tabla)',
            page: '17',
            url: self::REDE_URL,
            levels: self::REDE_LEVELS,
        );

        $this->scale(
            key: 'anep_upper_secondary',
            framework: 'ANEP Bachillerato',
            level: 'upper_secondary',
            name: 'Educación Media Superior · niveles de avance',
            issuingBody: 'ANEP',
            document: 'REEMS 2024 (Educación Media Superior 1.º a 3.º), tabla',
            page: '16',
            url: self::REEMS_URL,
            levels: self::REEMS_LEVELS,
        );
    }

    /**
     * @param  list<array{0: string, 1: string}>  $levels
     */
    protected function scale(
        string $key,
        string $framework,
        string $level,
        string $name,
        string $issuingBody,
        string $document,
        string $page,
        string $url,
        array $levels,
    ): void {
        DB::transaction(function () use ($key, $framework, $level, $name, $issuingBody, $document, $page, $url, $levels): void {
            $scale = OfficialScale::query()->updateOrCreate(['key' => $key], [
                'curricular_framework_id' => CurricularFramework::query()->where('name', $framework)->firstOrFail()->id,
                'education_level_id' => EducationLevel::query()->where('key', $level)->firstOrFail()->id,
                'name' => $name,
                'issuing_body' => $issuingBody,
                'citation_document' => $document,
                'citation_page' => $page,
                'source_url' => $url,
            ]);

            foreach ($levels as $index => [$levelName, $description]) {
                $scale->levels()->updateOrCreate(
                    ['position' => $index + 1],
                    ['name' => $levelName, 'description' => $description],
                );
            }
        });
    }
}
