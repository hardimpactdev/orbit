<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Services\DatabaseConnections\DatabaseConnectionRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('DatabaseConnectionRestorer', function (): void {
    it('updates mapped env keys and preserves unrelated content', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $path = storage_path('framework/testing/database-restorer-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', <<<ENV
# Existing comment
APP_NAME=Docs
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
KEEP_ME=yes
ENV);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        $target = DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        app(DatabaseConnectionRestorer::class)->restore($target);

        expect(File::get($path.'/.env'))->toContain('# Existing comment')
            ->toContain('APP_NAME=Docs')
            ->toContain('KEEP_ME=yes')
            ->toContain('DB_CONNECTION=pgsql')
            ->toContain('DB_HOST=db.internal')
            ->toContain('DB_PORT=5432')
            ->toContain('DB_DATABASE=docs')
            ->toContain('DB_USERNAME=orbit')
            ->toContain('DB_PASSWORD=secret');
    });
});
