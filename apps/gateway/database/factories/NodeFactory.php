<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('node-####'),
            'host' => fake()->unique()->bothify('node-####.test'),
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => NodeStatus::Active,
            'tld' => fake()->unique()->bothify('node-####'),
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
            'wireguard_address' => $this->wireguardAddress(),
            'managed' => false,
        ];
    }

    public function operator(): static
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function withActiveRole(string $role, array $settings = []): static
    {
        return $this->afterCreating(function (Node $node) use ($role, $settings): void {
            NodeRoleAssignment::factory()->create([
                'node_id' => $node->id,
                'role' => $role,
                'status' => NodeRoleStatus::Active,
                'settings' => $settings,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function appDev(array $settings = []): static
    {
        $tld = $settings['tld'] ?? null;
        unset($settings['tld']);

        $factory = is_string($tld) && $tld !== ''
            ? $this->state(['tld' => $tld])
            : $this;

        return $factory->withActiveRole('app-dev', $settings);
    }

    public function appProd(): static
    {
        return $this->withActiveRole('app-prod');
    }

    public function gateway(): static
    {
        return $this->withActiveRole('gateway');
    }

    public function vpn(): static
    {
        return $this->withActiveRole('vpn');
    }

    public function router(): static
    {
        return $this->withActiveRole('router');
    }

    public function database(): static
    {
        return $this->withActiveRole('database');
    }

    public function agent(): static
    {
        return $this->withActiveRole('agent');
    }

    public function managed(): static
    {
        return $this->state([
            'managed' => true,
        ]);
    }

    public function ingress(): static
    {
        return $this->withActiveRole('ingress');
    }

    private function wireguardAddress(): string
    {
        $address = long2ip(fake()
            ->unique()
            ->numberBetween(
                (int) ip2long('10.250.0.1'),
                (int) ip2long('10.250.255.254'),
            ));

        if (! is_string($address)) {
            throw new RuntimeException('Unable to create a private WireGuard test address.');
        }

        return $address;
    }
}
