<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\AppSetupRun;
use App\Models\AppSetupStep;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const APP_SETUP_CALLER_WG_IP = '10.6.0.97';

final class AppSetupControllerTestShell implements RemoteShell
{
    public array $runs = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = compact('node', 'script', 'options');

        return new RemoteShellResult(
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 25,
        );
    }
}

function createAppSetupCallerNode(array $permissions = ['app:write']): Node
{
    $caller = Node::factory()->create([
        'name' => 'app-setup-caller',
        'host' => APP_SETUP_CALLER_WG_IP,
        'wireguard_address' => APP_SETUP_CALLER_WG_IP,
    ]);

    return $caller;
}

function grantAppSetupAccess(Node $caller, Node $appNode, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createAppSetupTarget(): array
{
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
            'user' => 'orbit',
        ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);

    return [$node, $app, $instance];
}

describe('AppSetupController', function (): void {
    it('runs configured setup steps for authorized callers', function (): void {
        [$node, $app, $instance] = createAppSetupTarget();
        $caller = createAppSetupCallerNode();
        grantAppSetupAccess($caller, $node, ['app:write']);
        AppSetupStep::factory()->create([
            'app_instance_id' => $instance->id,
            'command' => 'npm install',
            'sort_order' => 1,
        ]);
        $shell = new AppSetupControllerTestShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/apps/docs/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app', 'docs')
            ->assertJsonPath('success.data.app_instance', 'development')
            ->assertJsonPath('success.data.setup_steps.status', 'completed')
            ->assertJsonPath('success.data.setup_steps.count', 1)
            ->assertJsonPath('success.meta', []);
        expect($response->getContent())->toContain('"meta":[]');

        expect($shell->runs)
            ->toHaveCount(1)
            ->and(AppSetupRun::query()->where('app_instance_id', $instance->id)->where('status', 'completed')->exists())
            ->toBeTrue();

        $activity = Activity::query()->first();

        expect($activity)
            ->not
            ->toBeNull()
            ->and($activity->subject_type)
            ->toBe(AppInstance::class)
            ->and($activity->subject_id)
            ->toBe($instance->id)
            ->and($activity->properties->get('app'))
            ->toBe('docs')
            ->and($activity->properties->get('app_instance'))
            ->toBe('development')
            ->and($activity->properties->get('status'))
            ->toBe('completed');
    });

    it('rejects callers without app write permission', function (): void {
        [$node] = createAppSetupTarget();
        $caller = createAppSetupCallerNode();
        grantAppSetupAccess($caller, $node, ['app:read']);

        $response = $this->call(
            'POST',
            '/api/apps/docs/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:write')
            ->assertJsonPath('error.meta.app_instance', 'development');
    });

    it('requires a concrete instance when a bare app selector is ambiguous', function (): void {
        [$node, $app] = createAppSetupTarget();
        AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: '/srv/docs',
                document_root: 'public',
                domain: 'docs.example.com',
            ),
        ]);
        $caller = createAppSetupCallerNode();
        grantAppSetupAccess($caller, $node, ['app:write']);

        $response = $this->call(
            'POST',
            '/api/apps/docs/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'app_instance_required')
            ->assertJsonPath('error.meta.instances', ['development', 'production']);
    });

    it('requires a concrete selector without exposing a hidden sibling', function (): void {
        [$visibleNode, $app] = createAppSetupTarget();
        $hiddenNode = Node::factory()->appDev()->create(['name' => 'app-hidden']);
        AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $hiddenNode->id,
                node: $hiddenNode->name,
                path: '/srv/docs',
                document_root: 'public',
                domain: 'docs.example.com',
            ),
        ]);
        $caller = createAppSetupCallerNode();
        grantAppSetupAccess($caller, $visibleNode, ['app:write']);
        app()->instance(RemoteShell::class, new AppSetupControllerTestShell);

        $response = $this->call(
            'POST',
            '/api/apps/docs/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'app_instance_required');

        expect($response->json('error.meta'))
            ->not->toHaveKey('instances')->and($response->content())
            ->not->toContain('production');
    });

    it('does not reveal whether an unauthorized explicit sibling exists', function (): void {
        [$visibleNode, $app] = createAppSetupTarget();
        $hiddenNode = Node::factory()->appDev()->create(['name' => 'app-hidden']);
        AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $hiddenNode->id,
                node: $hiddenNode->name,
                path: '/srv/docs',
                document_root: 'public',
                domain: 'docs.example.com',
            ),
        ]);
        $caller = createAppSetupCallerNode();
        grantAppSetupAccess($caller, $visibleNode, ['app:write']);
        app()->instance(RemoteShell::class, new AppSetupControllerTestShell);

        $hidden = $this->call(
            'POST',
            '/api/apps/docs.production/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
        );
        $missing = $this->call(
            'POST',
            '/api/apps/docs.does-not-exist/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
        );
        $normalize = static function (TestResponse $response): array {
            /** @var array<string, mixed> $error */
            $error = $response->json('error');
            unset($error['message']);

            if (is_array($error['meta'] ?? null)) {
                $error['meta']['instance'] = '<selector>';
            }

            return $error;
        };

        $hidden->assertUnprocessable();
        $missing->assertUnprocessable();

        expect($normalize($hidden))
            ->toBe($normalize($missing))
            ->and($hidden->json('error.code'))
            ->toBe('validation_failed')
            ->and($hidden->json('error.meta'))
            ->not->toHaveKeys(['instances', 'missing_permission', 'serving_node']);
    });
});
