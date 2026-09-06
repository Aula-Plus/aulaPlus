<?php

namespace Database\Factories;

use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Models\AIProposal;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIProposal>
 */
class AIProposalFactory extends Factory
{
    /**
     * Like AnnualPlan, an AIProposal's school_id must match its group's school,
     * so it is derived from the (possibly factory-created) group rather than
     * set independently.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (AIProposal $proposal): void {
            $proposal->school_id ??= $proposal->group?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'requested_by_id' => User::factory(),
            'type' => AIProposalType::Unit,
            'input_parameters' => ['focus' => fake()->sentence()],
            'context_sent' => null,
            'raw_response' => null,
            'status' => AIProposalStatus::Pending,
            'error_message' => null,
            'applied_to_id' => null,
            'applied_to_type' => null,
        ];
    }

    public function completed(array $rawResponse): static
    {
        return $this->state(fn () => [
            'status' => AIProposalStatus::Completed,
            'raw_response' => $rawResponse,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => AIProposalStatus::Pending]);
    }
}
