<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnectionTarget>
 */
class DatabaseConnectionTargetFactory extends Factory
{
    protected $model = DatabaseConnectionTarget::class;

    public function definition(): array
    {
        return [
            'database_connection_id' => DatabaseConnection::factory(),
            'app_id' => App::factory(),
            'workspace_id' => null,
            'env_prefix' => 'DB',
        ];
    }
}
