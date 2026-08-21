<?php

namespace Database\Factories;

use App\Enums\CommentTone;
use App\Models\Comment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Comment $comment): void {
            $comment->school_id ??= $comment->commentable?->school_id;
        });
    }

    public function definition(): array
    {
        return [
            'author_id' => User::factory(),
            'commentable_type' => Student::class,
            'commentable_id' => Student::factory(),
            'content' => fake()->paragraph(),
            'tone' => fake()->randomElement(CommentTone::cases()),
            'visible_to' => null,
        ];
    }

    public function forSubject(Model $subject): static
    {
        return $this->state([
            'commentable_type' => $subject::class,
            'commentable_id' => $subject->getKey(),
            'school_id' => $subject->getAttribute('school_id'),
        ]);
    }

    public function tone(CommentTone $tone): static
    {
        return $this->state(['tone' => $tone]);
    }

    public function visibleTo(array $roles): static
    {
        return $this->state(['visible_to' => $roles]);
    }
}
