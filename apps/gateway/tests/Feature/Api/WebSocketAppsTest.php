<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Nodes\GatewayPrivateDnsRoute;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketPublicationManager;
use App\Domain\WebSocket\WebSocketRuntimeLifecycle;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\Nodes\Roles\WebSocketRoleBaseline;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeGatewayPrivateDnsRoute;
use Tests\Support\FakeNodeConverger;
use Tests\Support\FakeNodeRoleFirewallManager;
use Tests\Support\FakeToolManagerMaterializer;

beforeEach(function (): void {
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    app()->instance(NodeConverger::class, new FakeNodeConverger);
    app()->instance(NodeRoleFirewallManager::class, new FakeNodeRoleFirewallManager);
    app()->instance(GatewayPrivateDnsRoute::class, new FakeGatewayPrivateDnsRoute);
    app()->instance(PrivateDnsManager::class, new class implements PrivateDnsManager
    {
        public function converge(?Node $pendingNode = null): void {}
    });
    $this->runtime = new WebSocketAppsRuntimeFake;
    $converger = new WebSocketAppsRoleConverger(new WebSocketRoleBaseline(
        $this->runtime,
        new WebSocketAppsPublicationFake,
        app(WebSocketCredentialManager::class),
    ));
    app()->instance(RoleBaselineConverger::class, $converger);
    app()->instance(NodeRoleDependentCleaner::class, $converger);
    app()->instance(InstanceOperationPreflight::class, new WebSocketAppsEnvironmentAccess);
    app()->instance(InstanceEnvironmentReader::class, new WebSocketAppsEnvironmentAccess);
    app()->instance(InstanceEnvironmentWriter::class, new WebSocketAppsEnvironmentAccess);

    $this->caller = $this->markAsGateway(websocket_apps_node('gateway', '10.44.0.1'));
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
});

describe('websocket:app:create', function (): void {
    it('creates an app, never returns its secret, and converges the Reverb server with every app', function (): void {
        websocket_apps_activate_role($this->caller);

        $response = $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();

        $app = WebSocketApp::query()->where('name', 'hauzer')->sole();
        $response
            ->assertJsonPath('data', [
                'id' => $app->id,
                'name' => 'hauzer',
                'app_id' => $app->app_id,
                'key' => $app->key,
                'instance_ids' => [],
            ]);

        expect($response->getContent())->not->toContain($app->secret)
            ->and(DB::table('websocket_apps')->where('name', 'hauzer')->value('secret'))->not->toBe($app->secret)
            ->and(strlen($app->secret))->toBe(40)
            ->and($this->runtime->appIds())->toBe([
                WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->sole()->app_id,
                $app->app_id,
            ]);
    });

    it('keeps the app for the next converge when no websocket role is active', function (): void {
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();

        expect(WebSocketApp::query()->pluck('name')->all())->toBe(['hauzer'])
            ->and($this->runtime->converged)->toBe([]);
    });

    it('keeps the created app when the websocket converge fails', function (): void {
        websocket_apps_activate_role($this->caller);
        $this->runtime->failConverge = true;

        $response = $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'node_role.convergence_failed')
            ->assertJsonPath('error.details.error_code', 'websocket.runtime_convergence_failed');

        $app = WebSocketApp::query()->where('name', 'hauzer')->sole();
        expect($response->getContent())->not->toContain($app->secret)
            ->and($this->runtime->appIds())->toContain($app->app_id);
    });

    it('refuses the Gateway name, a taken name, and a name that is not a slug', function (string $name): void {
        WebSocketApp::query()->create(['name' => 'taken', 'app_id' => 'taken-id', 'key' => 'taken-key', 'secret' => 'taken-secret']);

        $this->postJson('/api/v1/websocket-apps', ['name' => $name])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(WebSocketApp::query()->count())->toBe(1);
    })->with(['gateway', 'taken', 'Hauzer', 'hauzer_app']);
});

describe('websocket:app:list and websocket:app:show', function (): void {
    it('lists the apps by name with their Instances and without secrets', function (): void {
        websocket_apps_activate_role($this->caller);
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();
        $instance = websocket_apps_instance();
        $this->putJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")->assertOk();

        $list = $this->getJson('/api/v1/websocket-apps')->assertOk();
        $show = $this->getJson('/api/v1/websocket-apps/hauzer')->assertOk();

        expect(array_column($list->json('data'), 'name'))->toBe(['gateway', 'hauzer'])
            ->and($show->json('data.instance_ids'))->toBe([$instance->id])
            ->and($show->json('data'))->not->toHaveKey('secret');

        foreach (WebSocketApp::query()->get() as $app) {
            expect($list->getContent())->not->toContain($app->secret)
                ->and($show->getContent())->not->toContain($app->secret);
        }
    });

    it('answers 404 for an unknown app', function (): void {
        $this->getJson('/api/v1/websocket-apps/missing')->assertNotFound();
    });
});

describe('websocket:app:destroy', function (): void {
    it('deletes the app and converges the Reverb server without it', function (): void {
        websocket_apps_activate_role($this->caller);
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();

        $this->deleteJson('/api/v1/websocket-apps/hauzer')
            ->assertOk()
            ->assertJsonPath('data.name', 'hauzer');

        expect(WebSocketApp::query()->pluck('name')->all())->toBe(['gateway'])
            ->and($this->runtime->appIds())->toBe([WebSocketApp::query()->sole()->app_id])
            ->and($this->runtime->converged)->toHaveCount(2);
    });

    it('refuses the Gateway app', function (): void {
        websocket_apps_activate_role($this->caller);

        $this->deleteJson('/api/v1/websocket-apps/gateway')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'websocket.app_reserved');

        expect(WebSocketApp::query()->count())->toBe(1);
    });

    it('keeps the app deleted when the websocket converge fails', function (): void {
        websocket_apps_activate_role($this->caller);
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();
        $app = WebSocketApp::query()->where('name', 'hauzer')->sole();
        $this->runtime->failConverge = true;

        $this->deleteJson('/api/v1/websocket-apps/hauzer')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'node_role.convergence_failed')
            ->assertJsonPath('error.details.error_code', 'websocket.runtime_convergence_failed');

        expect(WebSocketApp::query()->whereKey($app->id)->exists())->toBeFalse()
            ->and(WebSocketApp::query()->pluck('name')->all())->toBe(['gateway'])
            ->and($this->runtime->appIds())->not->toContain($app->app_id);
    });

    it('refuses an app that is still on an Instance', function (): void {
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();
        $instance = websocket_apps_instance();
        $this->putJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")->assertOk();

        $this->deleteJson('/api/v1/websocket-apps/hauzer')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'websocket.app_in_use');

        expect(WebSocketApp::query()->count())->toBe(1);
    });
});

describe('instance:websocket:add and instance:websocket:remove', function (): void {
    it('writes the app credentials into the stored environment without returning the secret', function (): void {
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();
        $app = WebSocketApp::query()->sole();
        $instance = websocket_apps_instance();

        $response = $this->putJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")
            ->assertOk()
            ->assertJsonPath('data', [
                'instance_id' => $instance->id,
                'name' => 'hauzer',
                'keys' => [
                    'BROADCAST_CONNECTION',
                    'REVERB_APP_ID',
                    'REVERB_APP_KEY',
                    'REVERB_APP_SECRET',
                    'REVERB_HOST',
                    'REVERB_PORT',
                    'REVERB_SCHEME',
                ],
                'operation' => 'attach',
                'changed' => true,
                'key_count' => 7,
            ]);

        expect($response->getContent())->not->toContain($app->secret)
            ->and(websocket_apps_stored_env($instance))->toBe([
                'BROADCAST_CONNECTION' => 'reverb',
                'REVERB_APP_ID' => $app->app_id,
                'REVERB_APP_KEY' => $app->key,
                'REVERB_APP_SECRET' => $app->secret,
                'REVERB_HOST' => 'reverb.orbit',
                'REVERB_PORT' => '443',
                'REVERB_SCHEME' => 'https',
            ])
            ->and(WebSocketAppTarget::query()->sole()->only(['websocket_app_id', 'instance_id']))
            ->toBe(['websocket_app_id' => $app->id, 'instance_id' => $instance->id]);
    });

    it('replaces the app an Instance holds', function (): void {
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer-next'])->assertCreated();
        $instance = websocket_apps_instance();
        $this->putJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")->assertOk();

        $this->putJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer-next")->assertOk();

        $next = WebSocketApp::query()->where('name', 'hauzer-next')->sole();
        expect(WebSocketAppTarget::query()->sole()->websocket_app_id)->toBe($next->id)
            ->and(websocket_apps_stored_env($instance)['REVERB_APP_SECRET'])->toBe($next->secret);
    });

    it('refuses the Gateway app, so its secret never reaches an Instance', function (): void {
        websocket_apps_activate_role($this->caller);
        $instance = websocket_apps_instance();

        $this->putJson("/api/v1/instances/{$instance->id}/websocket-apps/gateway")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'websocket.app_reserved');

        expect(websocket_apps_stored_env($instance))->toBe([])
            ->and(WebSocketAppTarget::query()->count())->toBe(0);
    });

    it('removes the keys and the attachment, and keeps other stored keys', function (): void {
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();
        $instance = websocket_apps_instance();
        $instance->environmentValues()->create(['env_key' => 'APP_NAME', 'env_value' => 'Hauzer']);
        $this->putJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")->assertOk();

        $this->deleteJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")
            ->assertOk()
            ->assertJsonPath('data.operation', 'detach')
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.key_count', 1);

        expect(websocket_apps_stored_env($instance))->toBe(['APP_NAME' => 'Hauzer'])
            ->and(WebSocketAppTarget::query()->count())->toBe(0);
    });

    it('answers 404 when the app is not on the Instance', function (): void {
        $this->postJson('/api/v1/websocket-apps', ['name' => 'hauzer'])->assertCreated();
        $instance = websocket_apps_instance();

        $this->deleteJson("/api/v1/instances/{$instance->id}/websocket-apps/hauzer")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'websocket.attachment_missing');
    });
});

function websocket_apps_node(string $name, string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1),
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

/** An active role whose first converge created the Gateway app. */
function websocket_apps_activate_role(Node $node): void
{
    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
    app(WebSocketCredentialManager::class)->ensure($node);
}

function websocket_apps_instance(): Instance
{
    $node = websocket_apps_node('hauzer-node', '10.44.0.30');
    orbit_test_set_app_placement_role($node, false);
    $project = Project::query()->create([
        'name' => 'Hauzer',
        'slug' => 'hauzer',
        'repository_url' => 'https://example.test/hauzer.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/hauzer/default',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => 'active',
    ])->fresh(['node']);
}

/** @return array<string, string> */
function websocket_apps_stored_env(Instance $instance): array
{
    return InstanceEnvironmentValue::query()
        ->where('instance_id', $instance->id)
        ->orderBy('env_key')
        ->get()
        ->mapWithKeys(static fn (InstanceEnvironmentValue $row): array => [$row->env_key => $row->env_value])
        ->all();
}

/** Converges the `websocket` role through its real baseline, and only that role. */
final readonly class WebSocketAppsRoleConverger implements NodeRoleDependentCleaner, RoleBaselineConverger
{
    public function __construct(private WebSocketRoleBaseline $websocket) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        if ($assignment->role !== RoleName::WebSocket) {
            throw new LogicException("Unexpected role [{$assignment->role->value}].");
        }

        $this->websocket->converge($node, $assignment);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        throw new LogicException('Not used.');
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        throw new LogicException('Not used.');
    }

    public function clean(NodeRoleDependencySet $dependencies): void {}
}

/** Records what each converge would render into the Reverb server's `.env`. */
final class WebSocketAppsRuntimeFake implements WebSocketRuntimeLifecycle
{
    /** @var list<WebSocketServerCredentials> */
    public array $converged = [];

    public bool $failConverge = false;

    public function converge(Node $node, NodeRole $assignment, WebSocketServerCredentials $credentials): void
    {
        $this->converged[] = $credentials;

        if ($this->failConverge) {
            throw new NodeRoleOperationException(
                step: 'websocket-runtime',
                errorCode: 'node_role.convergence_failed',
                underlyingErrorCode: 'websocket.runtime_convergence_failed',
                message: 'WebSocket runtime convergence failed.',
            );
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

    public function health(Node $node): bool
    {
        return true;
    }

    /** @return list<string> */
    public function appIds(): array
    {
        $last = $this->converged[array_key_last($this->converged)];

        return array_map(static fn (WebSocketCredentials $app): string => $app->appId, $last->apps);
    }
}

final class WebSocketAppsPublicationFake implements WebSocketPublicationManager
{
    public function converge(Node $node): void {}

    public function checkListenAddresses(Node $node): void {}

    public function remove(Node $node): void {}

    public function retire(Node $node): void {}

    public function removeUnreachable(Node $node): void {}
}

final class WebSocketAppsEnvironmentAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight
{
    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }
}
