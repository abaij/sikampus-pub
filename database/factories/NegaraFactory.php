<?php

namespace Database\Factories;

use App\Models\Negara;
use Illuminate\Database\Eloquent\Factories\Factory;

class NegaraFactory extends Factory
{
    protected $model = Negara::class;

    public function definition(): array
    {
        return [
            'nama' => fake()->unique()->country(),
            'kode' => fake()->unique()->countryCode(),
        ];
    }
}
