<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'owner_id' => 1, // You can override this in the seeder
            'code' => strtoupper($this->faker->bothify('???###')),
            'group_number' => 1,
        ];
    }
}
