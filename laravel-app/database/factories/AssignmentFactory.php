<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Assignment>
 */
class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => 'Tugas ' . fake()->numberBetween(1, 10) . ' - ' . fake()->words(3, true),
            'description' => fake()->paragraph(),
            'due_date' => now()->addWeek(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['due_date' => now()->subDay()]);
    }
}
