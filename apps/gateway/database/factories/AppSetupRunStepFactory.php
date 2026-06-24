<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppSetupRun;
use App\Models\AppSetupRunStep;
use App\Models\AppSetupStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppSetupRunStep>
 */
class AppSetupRunStepFactory extends Factory
{
    protected $model = AppSetupRunStep::class;

    public function definition(): array
    {
        return [
            'app_setup_run_id' => AppSetupRun::factory(),
            'app_setup_step_id' => AppSetupStep::factory(),
            'command' => 'composer install',
            'exit_code' => null,
            'output' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
