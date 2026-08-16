<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    public function definition(): array
    {
        // The App is logical-only: it owns no placement or adoption. Placement
        // is created explicitly via placedOn(), which attaches a concrete Orbit
        // Instance.
        return [
            'name' => fake()->unique()->slug(2),
            'repository' => null,
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
            'runtime_config' => null,
        ];
    }

    public function static(): static
    {
        return $this->state(fn (): array => [
            'runtime' => AppRuntimeKind::Static,
        ]);
    }

    /**
     * Attach a concrete Orbit Instance carrying the app's placement on a node.
     * This is the explicit, instance-authoritative replacement for the removed
     * App node/path/document_root/domain/adopted columns.
     */
    public function placedOn(
        Node $node,
        string $instanceName = 'development',
        ?string $path = null,
        string $documentRoot = 'public',
        ?string $domain = null,
        bool $adopted = false,
    ): static {
        return $this->afterCreating(function (App $app) use (
            $node,
            $instanceName,
            $path,
            $documentRoot,
            $domain,
            $adopted,
        ): void {
            Instance::factory()->for($app, 'app')->create([
                'name' => $instanceName,
                'driver' => InstanceDriver::Orbit,
                'adopted' => $adopted,
                // Snapshot the app's runtime version onto the instance, matching
                // production ensureDefaultInstance; never rely on factory defaults.
                'php_version' => $app->php_version,
                'driver_config' => new OrbitInstanceDriverConfigData(
                    node_id: $node->id,
                    node: $node->name,
                    path: $path ?? '/home/orbit/apps/'.$app->name,
                    document_root: $documentRoot,
                    domain: $domain,
                ),
            ]);
        });
    }
}
