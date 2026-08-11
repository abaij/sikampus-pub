<?php

namespace Database\Factories;

use App\Models\Negara;
use App\Models\Provinsi;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProvinsiFactory extends Factory
{
    protected $model = Provinsi::class;

    public function definition(): array
    {
        return [
            'nama' => fake()->unique()->state(),
            'kode' => fake()->unique()->numerify('##'),
            'id_negara' => Negara::factory(),
        ];
    }
}
