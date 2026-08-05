<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceEnvInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('preserves an existing workspace env file', function (): void {
    $path = storage_path('framework/testing/workspace-env-existing');
    File::ensureDirectoryExists($path);
    File::put($path.'/.env', "APP_ENV=production\nCUSTOM_VALUE=keep-me\n");
    File::put($path.'/.env.example', "APP_ENV=local\nCUSTOM_VALUE=replace-me\n");
    $workspace = workspace_env_initializer_workspace($path);

    expect(app(WorkspaceEnvInitializer::class)->initialize($workspace))
        ->toBeFalse()
        ->and(File::get($path.'/.env'))
        ->toContain("APP_ENV=production\n")
        ->toContain("CUSTOM_VALUE=keep-me\n")
        ->toContain("APP_URL=https://feature-mail.billing.test\n");
});

it('initializes a missing workspace env from its own example without reading the parent env', function (): void {
    $path = storage_path('framework/testing/workspace-env-missing');
    File::ensureDirectoryExists($path);
    File::delete($path.'/.env');
    File::put($path.'/.env.example', "APP_ENV=local\nAPP_URL=http://localhost\n");
    $workspace = workspace_env_initializer_workspace($path);
    File::put($workspace->app->path.'/.env', "APP_ENV=production\nPARENT_SECRET=must-not-copy\n");

    expect(app(WorkspaceEnvInitializer::class)->initialize($workspace))
        ->toBeTrue()
        ->and(File::get($path.'/.env'))
        ->toContain('APP_ENV=local')
        ->toContain("APP_URL=https://feature-mail.billing.test\n")
        ->not->toContain('PARENT_SECRET');
});

function workspace_env_initializer_workspace(string $path): Workspace
{
    $node = Node::factory()
        ->gateway()
        ->create([
            'status' => 'active',
            'user' => 'orbit',
            'tld' => 'test',
        ]);
    $appPath = storage_path('framework/testing/workspace-env-parent');
    File::ensureDirectoryExists($appPath);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'billing',
        'path' => $appPath,
        'domain' => 'billing.test',
    ]);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: $appPath,
            domain: 'billing.test',
        ),
    ]);

    return Workspace::factory()
        ->for($app)
        ->for($instance, 'instance')
        ->create([
            'name' => 'feature-mail',
            'path' => $path,
        ]);
}
