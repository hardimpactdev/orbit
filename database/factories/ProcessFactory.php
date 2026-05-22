<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Process;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Process>
 */
class ProcessFactory extends Factory
{
    protected $model = Process::class;

    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'name' => fake()->unique()->slug(1),
            'command' => 'php artisan queue:work',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::None,
            'runtime' => ProcessRuntime::Docker,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
