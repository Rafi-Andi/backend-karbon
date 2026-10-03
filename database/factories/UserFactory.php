<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('+6281#########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'warga',
            'kota' => 'Surabaya',
            'kecamatan' => 'Gubeng',
            'kelurahan' => fake()->city(),
            'rt' => fake()->numerify('00#'),
            'rw' => fake()->numerify('0#'),
            'is_active' => true,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function warga(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'warga',
        ]);
    }

    public function mitra(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'mitra',
            'phone' => fake()->unique()->numerify('+6282#########'),
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
