<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceNodeServiceDetachProjection;
use App\Domain\Instances\InstanceNodeServiceTargets;
use App\Domain\Instances\InstanceState;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ApiOperationTool;
use App\Http\Mcp\ToolManifest;
use App\Infrastructure\Firewall\ContributedNodeFirewallRules;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process as OrbitProcess;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request as McpRequest;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** @return array{Instance, Node, array<string, int|string>} */
function nodeServiceAttachmentFixture(): array
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    $node->forceFill(['instance_bridge' => '10.253.0.0/24'])->save();
    $node->accessibleNodes()->attach($node);
    $project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/nckrtl/app.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/home/orbit/apps/app/default', 'status' => InstanceState::Active, 'first_setup_pending' => false]);

    $process = nodeServiceAttachmentProcess($node);

    return [$instance, $node, ['process_id' => $process->id, 'protocol' => 'tcp', 'port' => 1025,
        'host_env_key' => 'MAIL_HOST', 'port_env_key' => 'MAIL_PORT']];
}

/** @param list<string> $ports */
function nodeServiceAttachmentProcess(Node $node, array $ports = ['0.0.0.0:1025:1025/tcp']): OrbitProcess
{
    return OrbitProcess::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'mailpit',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'axllent/mailpit', 'ports' => $ports],
        'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    Queue::fake();
});

describe('Node service attachment records', function (): void {
    it('lists only the selected Instances ordered attachment identities without credentials or mutation', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $first = $instance->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
        $other = Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'name' => 'other',
            'checkout_path' => '/home/orbit/apps/app/other', 'status' => InstanceState::Active]);
        $other->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
        $second = $instance->nodeServiceTargets()->create(['node_id' => $node->id, ...$input,
            'host_env_key' => 'OTHER_HOST', 'port_env_key' => 'OTHER_PORT']);
        $process = OrbitProcess::query()->findOrFail($input['process_id']);
        $process->update(['runtime_config' => [...$process->runtime_config, 'environment' => ['SERVICE_PASSWORD' => 'list-secret-sentinel']]]);
        $before = $process->getRawOriginal();
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $response = $this->getJson("/api/v1/instances/{$instance->id}/node-service-targets")->assertOk();
        expect($response->json('data'))->toBe([
            ['id' => $first->id, 'instance_id' => $instance->id, 'node_id' => $node->id, ...$input],
            ['id' => $second->id, 'instance_id' => $instance->id, 'node_id' => $node->id, ...$input,
                'host_env_key' => 'OTHER_HOST', 'port_env_key' => 'OTHER_PORT'],
        ])->and($response->json('meta.request_id'))->toBe($response->headers->get('X-Orbit-Request-Id'))
            ->and($response->getContent())->not->toContain('list-secret-sentinel', 'environment', 'runtime_config')
            ->and($process->refresh()->getRawOriginal())->toBe($before);
        $this->assertDatabaseCount('instance_node_service_targets', 3);
        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('instance_environment_values', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
        Queue::assertNothingPushed();
    });

    it('returns an empty attachment list without recording work', function (): void {
        [$instance, $node] = nodeServiceAttachmentFixture();
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->getJson("/api/v1/instances/{$instance->id}/node-service-targets")->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/instances/999999/node-service-targets')->assertNotFound();
        $this->getJson('/api/v1/instances/app.example.test/node-service-targets')->assertNotFound();
        $this->assertDatabaseCount('operations', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
        Queue::assertNothingPushed();
    });

    it('requires an active peer and the selected Instances owning Node authority for reads', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $target = $instance->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
        $uri = "/api/v1/instances/{$instance->id}/node-service-targets";
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->getJson($uri)
            ->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown')->assertJsonMissingPath('data');
        $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'reader', 'status' => 'active',
            'platform' => 'linux', 'wireguard_ip' => '10.44.0.11', 'public_ssh_host' => '10.44.0.11', 'user' => 'orbit']));
        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson($uri)
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required')->assertJsonMissingPath('data');
        $caller->accessibleNodes()->attach($node);
        $this->getJson($uri)->assertOk()->assertJsonPath('data.0.id', $target->id);
        $this->assertDatabaseCount('operations', 0);
        $this->assertModelExists($target);
        Http::assertNothingSent();
        Process::assertNothingRan();
        Queue::assertNothingPushed();
    });

    it('attaches idempotently and detaches without executing or writing environment values', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $uri = "/api/v1/instances/{$instance->id}/node-service-targets";

        $attached = $this->postJson($uri, $input)->assertOk()->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.instance_id', $instance->id)->assertJsonPath('data.node_id', $node->id)
            ->assertJsonPath('data.protocol', 'tcp')->assertJsonPath('data.port', 1025)
            ->assertJsonPath('data.host_env_key', 'MAIL_HOST')->assertJsonPath('data.port_env_key', 'MAIL_PORT');
        $targetId = $attached->json('data.id');
        $this->assertDatabaseHas('instance_node_service_targets', ['id' => $targetId, ...$input, 'instance_id' => $instance->id]);
        $this->postJson($uri, $input)->assertOk()->assertJsonPath('data.id', $targetId)->assertJsonPath('data.changed', false)
            ->assertJsonPath('data.reason_code', 'instance.node_service_target_unchanged');
        $this->deleteJson($uri.'/'.$targetId)->assertAccepted()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.changed', true);

        $this->assertDatabaseCount('instance_node_service_targets', 0);
        $this->assertDatabaseCount('instance_environment_values', 0);
        $this->assertDatabaseCount('operations', 1);
        Http::assertNothingSent();
        Process::assertNothingRan();
        Queue::assertNothingPushed();
    });

    it('returns named refusals for a remote Node and a non-TCP protocol', function (string $case, string $code): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        if ($case === 'remote') {
            $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'shark', 'status' => 'active',
                'platform' => 'linux', 'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit']));
            $input['process_id'] = nodeServiceAttachmentProcess($other)->id;
        } else {
            $input['protocol'] = 'udp';
        }

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)
            ->assertUnprocessable()->assertJsonPath('error.code', $code);

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    })->with([
        'remote' => ['remote', 'instance.node_service_remote_node'],
        'UDP' => ['udp', 'instance.node_service_protocol_unsupported'],
    ]);

    it('refuses invalid input without recording a target', function (array $change): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", [...$input, ...$change])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    })->with([
        'port zero' => [['port' => 0]],
        'port too large' => [['port' => 65536]],
        'invalid key' => [['host_env_key' => 'MAIL-HOST']],
        'same keys' => [['port_env_key' => 'MAIL_HOST']],
        'unexpected field' => [['host' => '10.6.0.7']],
        'arbitrary Node' => [['node_id' => 999999]],
    ]);

    it('refuses conflicts across either environment key and retains the original port', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $uri = "/api/v1/instances/{$instance->id}/node-service-targets";
        $this->postJson($uri, $input)->assertOk();

        $this->postJson($uri, [...$input, 'port' => 2525])->assertUnprocessable()
            ->assertJsonPath('error.code', 'instance.node_service_port_unpublished');
        $this->postJson($uri, [...$input, 'host_env_key' => 'MAIL_PORT', 'port_env_key' => 'OTHER_PORT'])->assertConflict()
            ->assertJsonPath('error.code', 'instance.node_service_environment_conflict');

        $this->assertDatabaseCount('instance_node_service_targets', 1);
        $this->assertDatabaseHas('instance_node_service_targets', ['port' => 1025]);
    });

    it('does not detach another Instance target', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $target = InstanceNodeServiceTarget::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, ...$input]);
        $other = Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'name' => 'other',
            'checkout_path' => '/home/orbit/apps/app/other', 'status' => InstanceState::Active]);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->deleteJson("/api/v1/instances/{$other->id}/node-service-targets/{$target->id}")->assertNotFound()
            ->assertJsonPath('error.code', 'instance.node_service_target_missing');

        $this->assertModelExists($target);
    });

    it('refuses attach and detach while migration owns the Instance', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $target = InstanceNodeServiceTarget::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, ...$input]);
        Operation::query()->create(['kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $node->id, 'status' => 'running', 'input' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $uri = "/api/v1/instances/{$instance->id}/node-service-targets";

        $this->postJson($uri, $input)->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy');
        $this->deleteJson($uri.'/'.$target->id)->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy');

        $this->assertModelExists($target);
    });

    it('refuses attach after the Instance starts running in a container', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $target = InstanceNodeServiceTarget::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, ...$input]);
        $instance->runtime()->create(['node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'v1', 'agent_version' => 'v1', 'power_state' => 'running']);
        SwampNodeFixture::ensure();
        $node->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key'])->save();
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $uri = "/api/v1/instances/{$instance->id}/node-service-targets";

        $this->postJson($uri, $input)->assertUnprocessable()->assertJsonPath('error.code', 'instance.node_service_runtime_unsupported');
        $this->deleteJson($uri.'/'.$target->id)->assertAccepted()->assertJsonPath('data.status', 'queued');

        $this->assertModelExists($target);
    });

    it('retains the record when an unfinished failed migration still has a runtime', function (string $status): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $target = $instance->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
        $instance->runtime()->create(['node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'v1', 'agent_version' => 'v1', 'power_state' => 'stopped']);
        Operation::query()->create(['kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $node->id, 'status' => $status, 'input' => [], 'result' => ['moved' => false, 'restored' => false]]);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->deleteJson("/api/v1/instances/{$instance->id}/node-service-targets/{$target->id}")->assertConflict()
            ->assertJsonPath('error.code', 'instance.node_service_runtime_cleanup_required');

        $this->assertModelExists($target);
        $this->assertDatabaseCount('operations', 1);
        Http::assertNothingSent();
        Process::assertNothingRan();
        Queue::assertNothingPushed();
    })->with(['failed', 'interrupted']);

    it('attaches then detaches through generated MCP tools with numeric target identity', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $definition = collect(ToolManifest::default()->definitions())->firstWhere('name', 'instance-node-service-attach');
        $tool = new ApiOperationTool($definition, app(ApiDispatcher::class));
        $caller = HttpRequest::create('/mcp', 'POST', server: ['REMOTE_ADDR' => $node->wireguard_ip]);

        $response = $tool->handle(new McpRequest(['instance' => $instance->id, ...$input]), $caller);
        $data = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR)['data'];

        expect($data)->toMatchArray(['instance_id' => $instance->id, ...$input, 'changed' => true]);
        $this->assertDatabaseHas('instance_node_service_targets', ['instance_id' => $instance->id, 'node_id' => $node->id, ...$input]);
        $list = collect(ToolManifest::default()->definitions())->firstWhere('name', 'instance-node-service-list');
        expect($list->inputSchema['properties']['instance'])->toMatchArray(['type' => 'integer', 'minimum' => 1]);
        $listed = (new ApiOperationTool($list, app(ApiDispatcher::class)))->handle(new McpRequest(['instance' => $instance->id]), $caller);
        $records = json_decode((string) $listed->content(), true, flags: JSON_THROW_ON_ERROR)['data'];
        expect($records)->toBe([['id' => $data['id'], 'instance_id' => $instance->id, 'node_id' => $node->id, ...$input]]);
        $detach = collect(ToolManifest::default()->definitions())->firstWhere('name', 'instance-node-service-detach');
        expect($detach->inputSchema['properties']['target'])->toMatchArray(['type' => 'integer', 'minimum' => 1]);
        $removed = (new ApiOperationTool($detach, app(ApiDispatcher::class)))
            ->handle(new McpRequest(['instance' => $instance->id, 'target' => $data['id']]), $caller);
        $removedData = json_decode((string) $removed->content(), true, flags: JSON_THROW_ON_ERROR)['operation'];

        expect($removedData)->toMatchArray(['kind' => 'instance.node_service_detach', 'status' => 'succeeded']);
        expect($removedData['result'])->toMatchArray(['id' => $data['id'], 'process_id' => $input['process_id'], 'changed' => true]);
        $this->assertDatabaseCount('instance_node_service_targets', 0);
        $spec = json_decode(file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        expect($spec['paths']['/api/v1/instances/{instance}/node-service-targets/{target}']['delete']['description'])
            ->toContain('retains the record until withdrawal succeeds', 'no environment or Process runtime change');
        expect($spec['paths']['/api/v1/instances/{instance}/node-service-targets']['post']['responses']['200']['content']['application/json']['schema']['properties']['data'])
            ->toBe(['$ref' => '#/components/schemas/InstanceNodeServiceTarget']);
        expect($spec['paths']['/api/v1/instances/{instance}/node-service-targets/{target}']['delete']['responses']['202']['content']['application/json']['schema']['properties']['data'])
            ->toBe(['$ref' => '#/components/schemas/Operation']);
        expect($spec['components']['schemas']['InstanceNodeServiceTarget']['properties']['process_id'])->toBe(['type' => 'integer']);
        expect($spec['paths']['/api/v1/instances/{instance}/node-service-targets']['get']['responses']['200']['content']['application/json']['schema']['properties']['data'])
            ->toBe(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/InstanceNodeServiceTargetRecord']]);

    });

    it('selects the only published TCP port when port and protocol are omitted', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        unset($input['port'], $input['protocol']);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)->assertOk()
            ->assertJsonPath('data.port', 1025)->assertJsonPath('data.protocol', 'tcp');
    });

    it('refuses unregistered, unpublished, loopback and ambiguous Process endpoints before recording', function (array $ports, ?int $port, string $code): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $process = OrbitProcess::query()->findOrFail($input['process_id']);
        $process->update(['runtime_config' => ['ports' => $ports]]);
        if ($port === null) {
            unset($input['port']);
        } else {
            $input['port'] = $port;
        }
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)->assertUnprocessable()->assertJsonPath('error.code', $code);

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    })->with([
        'not published' => [[], 1025, 'instance.node_service_port_unpublished'],
        'UDP publication' => [['0.0.0.0:1025:1025/udp'], 1025, 'instance.node_service_port_unpublished'],
        'loopback' => [['127.0.0.1:1025:1025'], 1025, 'instance.node_service_listener_private'],
        'stale private address' => [['10.6.0.7:1025:1025'], 1025, 'instance.node_service_listener_private'],
        'multiple need choice' => [['1025:1025', '8025:8025'], null, 'instance.node_service_port_required'],
    ]);

    it('refuses a stopped desired state even while the Process remains active', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        OrbitProcess::query()->findOrFail($input['process_id'])->update(['desired_state' => 'stopped']);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)->assertUnprocessable()
            ->assertJsonPath('error.code', 'instance.node_service_process_stopped');

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    });

    it('refuses a Process without an active Docker publication', function (array $attributes): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        OrbitProcess::query()->findOrFail($input['process_id'])->update($attributes);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)->assertUnprocessable()
            ->assertJsonPath('error.code', 'instance.node_service_port_unpublished');

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    })->with(['systemd' => [['runtime' => 'systemd']], 'inactive' => [['status' => 'failed']]]);

    it('refuses a missing or Instance-owned Process', function (bool $instanceOwned): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        if ($instanceOwned) {
            OrbitProcess::query()->findOrFail($input['process_id'])->update(['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id]);
        } else {
            $input['process_id'] = 999999;
        }
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)->assertUnprocessable()
            ->assertJsonPath('error.code', 'instance.node_service_process_missing');

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    })->with([false, true]);

    it('refuses infrastructure ports even when a registered Process publishes one', function (int $port): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        OrbitProcess::query()->findOrFail($input['process_id'])->update(['runtime_config' => ['ports' => ["0.0.0.0:{$port}:1025"]]]);
        $input['port'] = $port;
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)->assertUnprocessable()
            ->assertJsonPath('error.code', 'instance.node_service_port_reserved');

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    })->with([22, 80, 443, 444, 2375, 2376, 3128, 3773, 3774, 3800, 3999, 8318, 8319, 8790, 3306, 5432, 5672, 6379, 8000, 8080, 8443, 9000, 9090, 9200, 11211, 15672, 27017]);

    it('reserves configured SSH and extension-owned ports through the shared catalog', function (string $case, int $port): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        if ($case === 'ssh') {
            $node->update(['public_ssh_port' => $port]);
        } else {
            $contribution = new class implements ContributedNodeFirewallRules
            {
                public function forNode(Node $node): array
                {
                    return [new UfwManagedRule(new UfwRuleShape('orbit:test-reserved', 'allow', 'in', 'any', 'any', '4545', 'tcp', 'orbit', null, 'v4'), [])];
                }
            };
            app()->instance($contribution::class, $contribution);
            app()->tag([$contribution::class], ContributedNodeFirewallRules::class);
        }
        OrbitProcess::query()->findOrFail($input['process_id'])->update(['runtime_config' => ['ports' => ["0.0.0.0:{$port}:1025"]]]);
        $input['port'] = $port;
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)->assertUnprocessable()
            ->assertJsonPath('error.code', 'instance.node_service_port_reserved');

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    })->with(['SSH' => ['ssh', 2222], 'extension' => ['extension', 4545]]);

    it('accepts the bridge binding and an explicit choice among published TCP ports', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        OrbitProcess::query()->findOrFail($input['process_id'])->update(['runtime_config' => ['ports' => ['10.253.0.1:1025:1025/tcp', '0.0.0.0:8025:8025/tcp']]]);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)->assertOk()->assertJsonPath('data.port', 1025);
    });

    it('requires an active caller and access to the Instance Node', function (): void {
        [$instance, $node, $input] = nodeServiceAttachmentFixture();
        $uri = "/api/v1/instances/{$instance->id}/node-service-targets";
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->postJson($uri, $input)->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
        $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'caller', 'status' => 'active',
            'platform' => 'linux', 'wireguard_ip' => '10.44.0.11', 'public_ssh_host' => '10.44.0.11', 'user' => 'orbit']));

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->postJson($uri, $input)->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');

        $this->assertDatabaseCount('instance_node_service_targets', 0);
    });
});

it('refuses an owner-driven Node Process on attach and when revalidating a stored target for migration', function (string $owner): void {
    [$instance, $node, $input] = nodeServiceAttachmentFixture();
    $process = OrbitProcess::query()->findOrFail($input['process_id']);
    if ($owner === 'database') {
        DatabaseServer::query()->create(['slug' => 'managed-database', 'driver' => 'mysql', 'node_id' => $node->id,
            'process_id' => $process->id, 'tag' => '8.4', 'port' => 1025, 'status' => 'active', 'root_password' => 'fixture-only']);
    } else {
        $contribution = new class($process->id) implements ProcessRemovalGuard
        {
            public function __construct(private readonly int $processId) {}

            public function assertRemovable(OrbitProcess $process): void
            {
                if ($process->id === $this->processId) {
                    throw new ResourceOperationException('process.required_by_fixture_service', 'Process [mailpit] belongs to the registered fixture service.', 409);
                }
            }
        };
        app()->instance($contribution::class, $contribution);
        app()->tag([$contribution::class], ProcessRemovalGuard::class);
    }
    $before = $process->getRawOriginal();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    $response = $this->postJson("/api/v1/instances/{$instance->id}/node-service-targets", $input)
        ->assertUnprocessable()->assertJsonPath('error.code', 'instance.node_service_process_owned');

    expect($response->json('error.message'))->toContain($owner === 'database' ? 'Database server [managed-database]' : 'registered fixture service');
    $this->assertDatabaseCount('instance_node_service_targets', 0);
    $target = InstanceNodeServiceTarget::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, ...$input]);
    try {
        InstanceNodeServiceTargets::assertValid($target, $instance);
        test()->fail('Migration must revalidate stored Process ownership.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.node_service_process_owned');
    }

    expect($process->refresh()->getRawOriginal())->toEqual($before);
    $this->assertModelExists($target);
    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('instance_runtimes', 0);
    Http::assertNothingSent();
    Process::assertNothingRan();
    Queue::assertNothingPushed();
})->with(['database', 'registered service']);

/** @return array<string, mixed> */
function nodeServiceWithdrawalResult(Operation $operation, string $status = 'succeeded', ?string $code = null): array
{
    return ['run_id' => 'withdrawal-run', 'status' => $status, 'error_code' => $code,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Revoke container access' => ['status' => $status]],
            'access' => json_encode($status === 'succeeded' ? ['target_id' => $operation->input['target_id'], 'detached' => true]
                : ['error_code' => $code], JSON_THROW_ON_ERROR)]];
}

function nodeServiceWithdrawalRuntime(Instance $instance): InstanceRuntime
{
    SwampNodeFixture::ensure();
    $instance->node->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key'])->save();

    return $instance->runtime()->create(['node_id' => $instance->node_id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.'.($instance->id + 1),
        'runtime_version' => 'fixture', 'agent_version' => 'fixture', 'power_state' => 'running']);
}

it('withdraws a stopped service before deleting its container attachment and preserves shared records and environment', function (): void {
    [$instance, $node, $input] = nodeServiceAttachmentFixture();
    $uri = "/api/v1/instances/{$instance->id}/node-service-targets";
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $target = $this->postJson($uri, $input)->assertOk()->json('data.id');
    $shared = $this->postJson($uri, [...$input, 'host_env_key' => 'OTHER_HOST', 'port_env_key' => 'OTHER_PORT'])->assertOk()->json('data.id');
    nodeServiceWithdrawalRuntime($instance);
    $process = OrbitProcess::query()->findOrFail($input['process_id']);
    $process->update(['desired_state' => 'stopped']);
    $before = $process->getRawOriginal();
    $id = $this->deleteJson($uri.'/'.$target)->assertAccepted()->assertJsonPath('data.status', 'queued')->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['container']['withdrawal'])->toMatchArray(['host' => '10.253.0.1', 'port' => 1025,
        'instance_shared' => true, 'node_shared' => true]);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $target]);
    $this->deleteJson($uri.'/'.$shared)->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy');
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => 'withdrawal-run']]);
    app(InstanceNodeServiceDetachProjection::class)->apply($operation, nodeServiceWithdrawalResult($operation));
    $this->assertDatabaseMissing('instance_node_service_targets', ['id' => $target]);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $shared]);
    $this->assertDatabaseCount('instance_environment_values', 0);
    expect($process->refresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
    Process::assertNothingRan();
});

it('retains a failed withdrawal for a new keyed retry and deletes only after the retry succeeds', function (string $failure): void {
    [$instance, $node, $input] = nodeServiceAttachmentFixture();
    $target = $instance->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
    nodeServiceWithdrawalRuntime($instance);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $uri = "/api/v1/instances/{$instance->id}/node-service-targets/{$target->id}";
    $id = $this->deleteJson($uri, [], ['Idempotency-Key' => 'withdrawal-first'])->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['container']['withdrawal'])->toMatchArray(['instance_shared' => false, 'node_shared' => false]);
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => 'withdrawal-run']]);
    $projection = app(InstanceNodeServiceDetachProjection::class);
    $projection->apply($operation, nodeServiceWithdrawalResult($operation, 'failed', $failure));
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $target->id]);
    $this->deleteJson($uri, [], ['Idempotency-Key' => 'withdrawal-first'])->assertAccepted()
        ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', $failure);
    $id = $this->deleteJson($uri, [], ['Idempotency-Key' => 'withdrawal-retry'])->assertAccepted()->assertJsonPath('data.status', 'queued')->json('data.id');
    $retry = Operation::query()->findOrFail($id);
    $retry->update(['status' => 'running', 'state' => ['swamp_run_id' => 'withdrawal-run']]);
    $projection->apply($retry, nodeServiceWithdrawalResult($retry));
    $projection->apply($retry, nodeServiceWithdrawalResult($retry));
    $this->assertDatabaseMissing('instance_node_service_targets', ['id' => $target->id]);
})->with(['instance.bridge_busy', 'instance.bridge_state_unresolved', 'instance.container_access_failed']);

it('does not delete the record on invalid proof or a late success after a failed terminal', function (bool $late): void {
    [$instance, $node, $input] = nodeServiceAttachmentFixture();
    $target = $instance->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
    nodeServiceWithdrawalRuntime($instance);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $id = $this->deleteJson("/api/v1/instances/{$instance->id}/node-service-targets/{$target->id}")->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => 'withdrawal-run']]);
    $projection = app(InstanceNodeServiceDetachProjection::class);
    $result = nodeServiceWithdrawalResult($operation);
    if ($late) {
        $projection->apply($operation, nodeServiceWithdrawalResult($operation, 'failed', 'operation.timed_out'));
    } else {
        $result['result']['access'] = json_encode(['target_id' => $target->id + 1, 'detached' => true]);
    }
    expect(fn () => $projection->apply($operation, $result))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $target->id]);
})->with([false, true]);

it('serializes Node withdrawals and preserves a different Instances shared service and a new checkout admission', function (): void {
    [$instance, $node, $input] = nodeServiceAttachmentFixture();
    $target = $instance->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
    nodeServiceWithdrawalRuntime($instance);
    $other = Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'name' => 'other',
        'checkout_path' => '/home/orbit/apps/app/other', 'status' => InstanceState::Active]);
    $shared = $other->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
    nodeServiceWithdrawalRuntime($other);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $id = $this->deleteJson("/api/v1/instances/{$instance->id}/node-service-targets/{$target->id}")->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['container']['withdrawal'])->toMatchArray(['instance_shared' => false, 'node_shared' => true]);
    $this->deleteJson("/api/v1/instances/{$other->id}/node-service-targets/{$shared->id}")->assertConflict()
        ->assertJsonPath('error.code', 'instance.lifecycle_busy');
    $new = Instance::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'name' => 'new',
        'checkout_path' => '/home/orbit/apps/app/new', 'status' => InstanceState::Active]);
    $admitted = $this->postJson("/api/v1/instances/{$new->id}/node-service-targets", $input)->assertOk()->json('data.id');
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => 'withdrawal-run']]);
    app(InstanceNodeServiceDetachProjection::class)->apply($operation, nodeServiceWithdrawalResult($operation));
    $this->assertDatabaseMissing('instance_node_service_targets', ['id' => $target->id]);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $shared->id]);
    $this->assertDatabaseHas('instance_node_service_targets', ['id' => $admitted]);
    expect(Operation::query()->where('kind', 'instance.node_service_detach')->count())->toBe(1);
});

it('rolls back terminal success if attachment deletion cannot commit', function (): void {
    [$instance, $node, $input] = nodeServiceAttachmentFixture();
    $target = $instance->nodeServiceTargets()->create(['node_id' => $node->id, ...$input]);
    nodeServiceWithdrawalRuntime($instance);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $id = $this->deleteJson("/api/v1/instances/{$instance->id}/node-service-targets/{$target->id}")->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => 'withdrawal-run']]);
    DB::statement("CREATE TRIGGER refuse_attachment_delete BEFORE DELETE ON instance_node_service_targets BEGIN SELECT RAISE(ABORT, 'fixture delete failure'); END");
    try {
        expect(fn () => app(InstanceNodeServiceDetachProjection::class)->apply($operation, nodeServiceWithdrawalResult($operation)))
            ->toThrow(QueryException::class);
        expect($operation->refresh()->status->value)->toBe('running');
        $this->assertDatabaseHas('instance_node_service_targets', ['id' => $target->id]);
    } finally {
        DB::statement('DROP TRIGGER refuse_attachment_delete');
    }
});
