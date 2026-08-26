<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\AppDevelopmentSetupStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppDevelopmentSetupStep> */
final class AppDevelopmentSetupStepFactory extends Factory
{
    #[\Override]
    protected $model = AppDevelopmentSetupStep::class;

    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'sort_order' => 1,
            'command' => 'composer install --no-interaction',
            'timeout_seconds' => 600,
        ];
    }
}
