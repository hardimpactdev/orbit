<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\InstanceRuntimeRequirementsData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instance>
 */
class InstanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData,
            'adopted' => false,
            'runtime_requirements' => new InstanceRuntimeRequirementsData,
            'deploy_warmup_paths' => null,
            'worker_enabled' => false,
            'worker_config' => null,
        ];
    }

    public function workerEnabled(?array $config = null): static
    {
        return $this->state(fn (): array => [
            'worker_enabled' => true,
            'worker_config' => $config ?? [
                'workers' => 'auto',
                'max_requests' => 500,
            ],
        ]);
    }
}
