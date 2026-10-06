<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Course>
 */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dosen_id' => User::factory()->dosen(),
            'code' => strtoupper(fake()->unique()->bothify('IF####')),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
        ];
    }
}
