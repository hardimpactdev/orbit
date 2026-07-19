<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppInstance;
use App\Models\AppSetupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppSetupRun>
 */
class AppSetupRunFactory extends Factory
{
    protected $model = AppSetupRun::class;

    public function definition(): array
    {
        return [
            'app_instance_id' => AppInstance::factory(),
            'status' => 'pending',
            'step_set_hash' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
