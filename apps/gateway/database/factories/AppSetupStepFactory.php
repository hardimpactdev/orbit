<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppSetupStep;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppSetupStep>
 */
class AppSetupStepFactory extends Factory
{
    protected $model = AppSetupStep::class;

    public function definition(): array
    {
        return [
            'instance_id' => Instance::factory(),
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => AppSetupStep::DEFAULT_TIMEOUT_SECONDS,
        ];
    }
}
