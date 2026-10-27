<?php

namespace Database\Factories;

use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->numberBetween(1, 7).'° '.fake()->randomLetter(),
            'level' => fake()->randomElement(['Inicial', 'Primaria', 'Secundaria']),
            // Seeded by migration; a level is mandatory for new groups.
            'education_level_id' => fn () => EducationLevel::query()->where('key', 'primary')->value('id'),
            'school_year' => now()->year,
            'group_profile' => null,
            'related_documents' => null,
        ];
    }

    /**
     * A group created before levels existed: "Sin nivel".
     */
    public function withoutLevel(): static
    {
        return $this->state(['education_level_id' => null]);
    }

    public function atLevel(string $key): static
    {
        return $this->state(['education_level_id' => fn () => EducationLevel::query()->where('key', $key)->value('id')]);
    }
}
