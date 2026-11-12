<?php

namespace App\Services\CurricularStructure;

use App\Enums\PlannerFamily;
use App\Models\CurricularOffering;
use App\Models\Group;
use App\Models\PlannerFieldDefinition;
use App\Models\SchoolPlannerField;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * Resolves the unit planner (screen 23 › Planificadores, screen 13): the
 * catalog fields with the school's names and visibility, plus the school's
 * own fields.
 *
 * Rules (screen 23): a field an external program asks for can be renamed but
 * never hidden — its official name stays next to the school's, because that is
 * the one the AI and the checks read. Fields are never mandatory to generate a
 * class (screen 13); `required_by_school` is informative.
 */
class PlannerFields
{
    /**
     * Every family with its catalog fields (school overrides applied) and the
     * school's own fields — the configuration screen. Includes hidden fields.
     *
     * @return list<array<string, mixed>>
     */
    public function settings(): array
    {
        $definitions = PlannerFieldDefinition::query()->orderBy('position')->get()->groupBy(fn ($d) => $d->family->value);
        $overrides = SchoolPlannerField::query()->whereNotNull('planner_field_definition_id')->get()
            ->keyBy('planner_field_definition_id');
        $custom = $this->customFields();

        return array_map(fn (PlannerFamily $family): array => [
            'family' => $family->value,
            'fields' => ($definitions[$family->value] ?? collect())
                ->map(fn (PlannerFieldDefinition $definition) => $this->catalogField($definition, $overrides->get($definition->id)))
                ->values()
                ->all(),
            'custom_fields' => $custom
                ->filter(fn (SchoolPlannerField $field) => $this->customFamily($field) === $family->value)
                ->map(fn (SchoolPlannerField $field) => $this->customField($field))
                ->values()
                ->all(),
        ], PlannerFamily::cases());
    }

    /**
     * The planner a unit of this group-subject shows: the common fields, then
     * the fields of each framework the group-subject follows (offering), with
     * the school's own fields after their family. Hidden fields are left out.
     *
     * @return array{frameworks: list<array<string, mixed>>, fields: list<array<string, mixed>>}
     */
    public function forGroupSubject(Group $group, Subject $subject): array
    {
        $frameworks = CurricularOffering::query()
            ->forGroupSubject($group->id, $subject->id)
            ->with('curricularFramework')
            ->orderBy('id')
            ->get()
            ->map->curricularFramework
            ->filter()
            ->values();

        $families = $frameworks
            ->map(fn ($framework) => $framework->planner_family)
            ->filter()
            ->unique()
            ->values();
        $familyOrder = collect([PlannerFamily::Common])->concat($families)->map->value->values();

        $definitions = PlannerFieldDefinition::query()
            ->whereIn('family', $familyOrder->all())
            ->orderBy('position')
            ->get();
        $overrides = SchoolPlannerField::query()->whereNotNull('planner_field_definition_id')->get()
            ->keyBy('planner_field_definition_id');
        $custom = $this->customFields();

        $fields = $familyOrder->flatMap(fn (string $family) => $definitions
            ->filter(fn (PlannerFieldDefinition $definition) => $definition->family->value === $family)
            ->map(fn (PlannerFieldDefinition $definition) => $this->catalogField($definition, $overrides->get($definition->id)))
            ->concat($custom
                ->filter(fn (SchoolPlannerField $field) => $this->customFamily($field) === $family)
                ->map(fn (SchoolPlannerField $field) => $this->customField($field)))
        )->filter(fn (array $field) => $field['shown'])->values()->all();

        return [
            'frameworks' => $frameworks->map(fn ($framework) => [
                'id' => $framework->id,
                'name' => $framework->name,
                'planner_family' => $framework->planner_family?->value,
            ])->all(),
            'fields' => $fields,
        ];
    }

    /** @return Collection<int, SchoolPlannerField> */
    protected function customFields(): Collection
    {
        return SchoolPlannerField::query()
            ->whereNull('planner_field_definition_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** Own fields for every framework sit with the common ones. */
    protected function customFamily(SchoolPlannerField $field): string
    {
        return $field->family?->value ?? PlannerFamily::Common->value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function catalogField(PlannerFieldDefinition $definition, ?SchoolPlannerField $override): array
    {
        return [
            'id' => $definition->id,
            'source' => 'catalog',
            'family' => $definition->family->value,
            'key' => $definition->key,
            'official_name' => $definition->official_name,
            'school_name' => $override?->name,
            'type' => $definition->type->value,
            'options' => $definition->options,
            'required_by_program' => $definition->required_by_program,
            'required_by_school' => false,
            // A field the program asks for is always shown, whatever was stored.
            'shown' => $definition->required_by_program || ($override?->shown ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customField(SchoolPlannerField $field): array
    {
        return [
            'id' => $field->id,
            'source' => 'school',
            'family' => $field->family?->value,
            'key' => 'school_'.$field->id,
            'official_name' => null,
            'school_name' => $field->name,
            'type' => $field->type?->value,
            'options' => $field->options,
            'required_by_program' => false,
            'required_by_school' => $field->required_by_school,
            'shown' => $field->shown,
        ];
    }
}
