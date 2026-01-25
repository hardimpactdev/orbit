<?php

namespace HardImpact\Orbit\Core\Database\Factories;

use HardImpact\Orbit\Core\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentFactory extends Factory
{
    protected $model = Environment::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'host' => $this->faker->ipv4(),
            'user' => $this->faker->userName(),
            'port' => 22,
            'is_local' => false,
            'is_default' => false,
            'status' => Environment::STATUS_ACTIVE,
        ];
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Local',
            'host' => '127.0.0.1',
            'user' => 'nckrtl',
            'is_local' => true,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Environment::STATUS_PROVISIONING,
        ]);
    }

    public function withError(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Environment::STATUS_ERROR,
            'provisioning_error' => 'Test error message',
        ]);
    }
}
