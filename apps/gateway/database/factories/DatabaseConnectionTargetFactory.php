<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Workspace;
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
            'app_instance_id' => AppInstance::factory(),
            'workspace_id' => null,
            'env_prefix' => 'DB',
        ];
    }

    public function forAppInstance(?AppInstance $instance = null): static
    {
        return $this->state(fn (): array => [
            'app_instance_id' => $instance instanceof AppInstance ? $instance->id : AppInstance::factory(),
            'workspace_id' => null,
        ]);
    }

    public function forWorkspace(?Workspace $workspace = null): static
    {
        return $this->state(fn (): array => [
            'app_instance_id' => null,
            'workspace_id' => $workspace instanceof Workspace ? $workspace->id : Workspace::factory(),
        ]);
    }
}
