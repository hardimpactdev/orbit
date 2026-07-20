<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\AppInstance;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppInstance>
 */
class AppInstanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id' => Project::factory(),
            'name' => 'development',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData,
            'runtime_requirements' => new AppInstanceRuntimeRequirementsData,
            'agent_ide_config' => null,
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
