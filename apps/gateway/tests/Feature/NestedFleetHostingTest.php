<?php

declare(strict_types=1);

use App\Actions\Schedules\RunScheduleAction;
use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Instances\BaselineNormalInstanceContainer;
use App\Domain\Instances\BaselineNormalInstanceHostIntent;
use App\Domain\Instances\ContainerInstanceDestroy;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/Domain/Instances/ContainerInstanceHelpers.php';
require_once __DIR__.'/../Fixtures/BaselineNormalInstanceHostIntent.php';
beforeEach(function (): void {
    Queue::fake();
    $this->withoutMiddleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class]);
});

function nestedSwitch(Node $node, bool $on): Node
{
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => $on]]);

    return $node->refresh();
}

/**
 * An Instance whose runtime records the nested project, as the image change will create one: the blanket refusal blocks reserve()
 * today, and the database keeps a recorded project from changing. So the row is the one reserve() makes, with the nested project.
 */
function recordedNestedInstance(Project $project, Node $node, string $name = 'default', ?Instance $source = null): Instance
{
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/test/'.$project->slug.'/'.$name]);
    $flag = $project->nested_fleet;
    $project->update(['nested_fleet' => false]);
    DB::beginTransaction();
    $row = app(InstanceContainer::class)->reserve($instance, $node, $source)->getAttributes();
    DB::rollBack();
    $project->update(['nested_fleet' => $flag]);
    unset($row['id']);
    InstanceRuntime::query()->create([...$row, 'incus_project' => InstancePlacement::NestedProject,
        'idmap_size' => $source?->runtime()->value('idmap_size') ?? InstanceHostIntent::TaskPlaceIds]);

    return $instance->refresh();
}

/** Task VM host settings with VM places and two task and one CI container place. @return array<string, mixed> */
function taskVms(int $taskPlaces, int $ciPlaces): array
{
    return NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'task_places' => $taskPlaces, 'ci_places' => $ciPlaces,
        'containers' => ['cidr' => '10.252.11.0/24', 'task_places' => 2, 'ci_places' => 1]], Ipv4Subnet::from('10.44.0.0/24'))->toArray();
}

function otherProject(string $slug, bool $nested = false): Project
{
    return Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package', 'repository_url' => 'https://github.com/nckrtl/'.$slug.'.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now(), 'nested_fleet' => $nested]);
}

function placementRefusal(Closure $decide): string
{
    try {
        return (string) $decide();
    } catch (ResourceOperationException $error) {
        return $error->errorCode.' '.$error->status;
    }
}

describe('the one placement rule', function (): void {
    it('places a normal Project as before and a nested-fleet Project only in a container of the nested project on a switched-on Node', function (): void {
        $listed = nestedSwitch(containerDefaultNode(), true);
        $off = containerDefaultNode('off', '10.44.0.8');
        $unlisted = nestedSwitch(containerDefaultNode('unlisted', '10.44.0.9', false), true);
        $normal = containerDefaultProject();
        $nested = otherProject('nested', true);

        expect(InstancePlacement::place($normal, $listed, true))->toBe('orbit-instances')
            ->and(InstancePlacement::place($normal, $off, true))->toBe('orbit-instances')
            ->and(InstancePlacement::place($normal, $unlisted, false))->toBeNull()
            ->and(placementRefusal(fn () => InstancePlacement::place($nested, $off, true)))->toBe('instance.nested_fleet_host_unavailable 409')
            ->and(placementRefusal(fn () => InstancePlacement::place($nested, $unlisted, true)))->toBe('instance.nested_fleet_container_required 409')
            ->and(placementRefusal(fn () => InstancePlacement::place($nested, $listed, false)))->toBe('instance.nested_fleet_container_required 409')
            ->and(InstancePlacement::place($nested, $listed, true))->toBe('orbit-instances-nested');
    });

    it('refuses a nested-fleet Project at the checkout and task-workspace entries and passes a normal Project', function (string $entry): void {
        $project = containerDefaultProject();
        InstancePlacement::{$entry}($project);
        $project->update(['nested_fleet' => true]);
        expect(placementRefusal(fn () => InstancePlacement::{$entry}($project)))->toBe('operation.kind_unavailable 503');
    })->with(['checkout', 'taskWorkspace']);

    it('checks the switch early for a writer that can make a container, before it writes anything', function (): void {
        $off = containerDefaultNode();
        $on = nestedSwitch(containerDefaultNode('shark', '10.44.0.8'), true);
        $project = containerDefaultProject();
        expect(placementRefusal(fn () => InstancePlacement::assert($project, $off)))->toBe('')
            ->and(placementRefusal(fn () => InstancePlacement::assert($project, $on)))->toBe('');
        $project->update(['nested_fleet' => true]);
        expect(placementRefusal(fn () => InstancePlacement::assert($project, $off)))->toBe('instance.nested_fleet_host_unavailable 409')
            // A switched-on Node lets it through to place(); a missing Node is the writer's own not-found, later.
            ->and(placementRefusal(fn () => InstancePlacement::assert($project, $on)))->toBe('')
            ->and(placementRefusal(fn () => InstancePlacement::assert($project, null)))->toBe('');
    });

    it('gives the nested project its six container keys and the normal project none', function (): void {
        expect(InstancePlacement::config('orbit-instances', null))->toBe([])
            ->and(InstancePlacement::config('orbit-instances-nested', 1165536)['security.idmap.size'])->toBe('1165536')
            ->and(InstancePlacement::config('orbit-instances-nested', 101000000))->toBe([
                'security.nesting' => 'true', 'security.syscalls.intercept.mknod' => 'true', 'security.syscalls.intercept.setxattr' => 'true',
                'security.idmap.isolated' => 'true', 'security.idmap.size' => '101000000', 'limits.memory' => '12GiB', 'boot.autostart' => 'false',
            ]);
    });
});

describe('the recorded Incus project', function (): void {
    it('records the normal project at reservation, and a clone records its default\'s project', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        expect(app(InstanceContainer::class)->reserve($default, $node)->incus_project)->toBe('orbit-instances');

        $nestedDefault = recordedNestedInstance(otherProject('other'), $node);
        $clone = Instance::query()->create(['project_id' => $nestedDefault->project_id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/feature']);
        expect(app(InstanceContainer::class)->reserve($clone, $node, $nestedDefault)->incus_project)->toBe('orbit-instances-nested');
    });

    it('names the workspace, the destroy target and the intent after the recorded project, not the Project flag', function (): void {
        $node = containerDefaultNode();
        $instance = recordedNestedInstance(containerDefaultProject(), $node);
        expect($instance->project->nested_fleet)->toBeFalse()
            ->and(InstanceContainer::workspaceOnNode($instance))->toBe('/var/lib/incus/storage-pools/orbit-instances/containers/orbit-instances-nested_instance-'.$instance->id.'/rootfs/srv/workspace')
            ->and(app(ContainerInstanceDestroy::class)->capture($instance)['project'])->toBe('orbit-instances-nested')
            ->and(app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail())['project'])->toBe('orbit-instances-nested');
    });

    it('gives a recorded nested container its keys and its exact device list for the start guard', function (bool $clone): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $instance = recordedNestedInstance($project, $node);
        if ($clone) {
            $instance->runtime()->firstOrFail()->update(['seed_commit' => str_repeat('a', 40)]);
            $instance = recordedNestedInstance($project, $node, 'feature', $instance);
        }
        $runtime = $instance->runtime()->firstOrFail();
        $intent = app(InstanceContainer::class)->intent($instance, $runtime);
        expect($intent['config'])->toMatchArray(InstancePlacement::config('orbit-instances-nested', $runtime->idmap_size))
            ->and($intent['config'])->not->toHaveKeys(['raw.lxc', 'raw.idmap', 'security.privileged'])
            // A nested runtime starts from the nested-fleet image; the normal intent below keeps `orbit-instance`.
            ->and($intent['image'])->toBe('orbit-instance-nested')
            ->and($intent['allowed_devices'])->toBe([
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => 'orbit-instances', 'size' => '40GiB'],
                'eth0' => ['type' => 'nic', 'name' => 'eth0', 'network' => 'orbitinst0', 'ipv4.address' => $runtime->bridge_address,
                    'security.port_isolation' => 'true', 'security.acls' => 'orbitinst0-proxy,'.$runtime->container_name,
                    'security.acls.default.egress.action' => 'reject', 'security.acls.default.ingress.action' => 'reject',
                    // The NIC accepts only its own IPv4 and MAC address, as create, clone and migrate write it.
                    'security.ipv4_filtering' => 'true', 'security.mac_filtering' => 'true'],
                'orbit-runtime' => ['type' => 'disk', 'source' => '/opt/orbit-runtime', 'path' => '/opt/orbit-runtime', 'readonly' => 'true', 'shift' => 'true'],
                'orbit-agent' => ['type' => 'disk', 'source' => '/opt/orbit-agent', 'path' => '/opt/orbit-agent', 'readonly' => 'true', 'shift' => 'true'],
                ...($clone ? ['t3' => ['type' => 'proxy', 'listen' => 'tcp:10.44.0.7:'.$runtime->t3_port, 'connect' => 'tcp:'.$runtime->bridge_address.':3773', 'nat' => 'true']] : []),
            ]);
    })->with([false, true]);
});

describe('the inner volume', function (): void {
    it('is recorded only for a new nested-fleet default, and its intent, start guard and removal name it', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $project = otherProject('nested', true);
        $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/nested/default']);
        $runtime = app(InstanceContainer::class)->reserve($default, $node);
        $intent = app(InstanceContainer::class)->intent($default, $runtime);

        expect($runtime->incus_project)->toBe('orbit-instances-nested')
            ->and($runtime->inner_volume)->toBe('inner-'.$default->id)
            ->and($intent['inner_volume'])->toBe(['name' => 'inner-'.$default->id, 'pool' => 'orbit-instances', 'size' => '30GiB',
                'config' => ['zfs.block_mode' => 'true', 'block.filesystem' => 'btrfs', 'block.mount_options' => 'user_subvol_rm_allowed,discard', 'security.shifted' => 'true'],
                'device' => 'inner-pool', 'path' => '/srv/inner-pool', 'inner_pool' => ['name' => 'inner', 'source' => '/srv/inner-pool/pool']])
            ->and($intent['allowed_devices']['inner-pool'])->toBe(['type' => 'disk', 'pool' => 'orbit-instances', 'source' => 'inner-'.$default->id, 'path' => '/srv/inner-pool'])
            ->and(app(ContainerInstanceDestroy::class)->capture($default)['inner_volume'])->toBe('inner-'.$default->id);

        // A clone of it records its own volume, which the clone copies from the default's seed
        // (docs/reference/development-containers.md#copies-of-the-volume).
        $clone = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/nested/feature']);
        expect(app(InstanceContainer::class)->reserve($clone, $node, $default)->inner_volume)->toBe('inner-'.$clone->id);

        // A non-default runtime with no clone source, as a migration reserves one, records none either.
        $moved = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'moved', 'checkout_path' => '/test/nested/moved']);
        expect(app(InstanceContainer::class)->reserve($moved, $node)->inner_volume)->toBeNull();
    });

    it('is absent for a normal default and for a nested runtime that records none, as Instance 2454', function (string $shape): void {
        $node = containerDefaultNode();
        $instance = $shape === 'normal'
            ? Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default'])
            : recordedNestedInstance(containerDefaultProject(), $node);
        $runtime = $shape === 'normal' ? app(InstanceContainer::class)->reserve($instance, $node) : $instance->runtime()->firstOrFail();
        $intent = app(InstanceContainer::class)->intent($instance, $runtime);

        expect($runtime->inner_volume)->toBeNull()
            ->and($intent)->not->toHaveKey('inner_volume')
            ->and($intent['allowed_devices'] ?? [])->not->toHaveKey('inner-pool')
            ->and(app(ContainerInstanceDestroy::class)->capture($instance))->not->toHaveKey('inner_volume');
    })->with(['normal', 'nested without a volume']);
});

describe('a normal Node and a normal Instance stay byte-identical to main', function (): void {
    it('keeps the full host intent of a normal Node with the switch absent or off', function (string $switch, bool $taskContainers): void {
        $this->freezeTime();
        $node = containerDefaultNode();
        $settings = [...$node->settings, 't3' => ['connection_id' => 'fixture']];
        if ($taskContainers) {
            $settings['task_vms'] = taskVms(1, 1);
        }
        if ($switch === 'off') {
            $settings['nested_fleet'] = false;
        }
        $node->update(['settings' => $settings]);
        // A normal Instance runtime on the Node changes nothing either.
        app(InstanceContainer::class)->reserve(Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id,
            'name' => 'default', 'checkout_path' => '/test/default']), $node->refresh());
        $expected = app(BaselineNormalInstanceHostIntent::class)->for($node);
        $actual = app(InstanceHostIntent::class)->for($node);
        // `own_site` came after the frozen copy: every Node gets it alike, and ContainerDevPortsTest pins it.
        expect(json_encode(array_diff_key($actual ?? [], ['own_site' => true]), JSON_THROW_ON_ERROR))->toBe(json_encode($expected, JSON_THROW_ON_ERROR))
            ->and($actual)->not->toHaveKeys(['nested', 'runtime_projects']);
    })->with(['absent', 'off'])->with([false, true]);

    it('keeps the full default and clone intents of a normal Instance', function (bool $clone): void {
        $this->freezeTime();
        $node = containerDefaultNode();
        nestedSwitch($node, true);
        $project = containerDefaultProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        $containers = app(InstanceContainer::class);
        $runtime = $containers->reserve($instance, $node);
        if ($clone) {
            $runtime->update(['seed_commit' => str_repeat('a', 40)]);
            $source = $instance;
            $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/feature']);
            $runtime = $containers->reserve($instance, $node, $source);
        }
        $expected = normalContainerSinceMain($instance, $runtime);
        if ($clone) {
            // The one change since main: every clone carries the files a seed may lack, before `files` (InstanceContainer::bootFiles).
            $actual = $containers->intent($instance, $runtime);
            $files = array_search('files', array_keys($expected['clone']), true);
            $expected['clone'] = [...array_slice($expected['clone'], 0, $files, true), 'boot_files' => $actual['clone']['boot_files'],
                ...array_slice($expected['clone'], $files, null, true)];
            expect(array_column($actual['clone']['boot_files'], 'path'))
                ->toBe(['/etc/ld.so.conf.d/zz-orbit-browser.conf', '/etc/fonts/fonts.conf', '/etc/gitconfig', '/etc/ld.so.cache', '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt',
                    '/home/agent/.pki/nssdb/pkcs11.txt', '/etc/systemd/system.conf.d/91-orbit-browsers.conf', '/etc/profile.d/91-orbit-browsers.sh']);
        }
        expect(json_encode($containers->intent($instance, $runtime), JSON_THROW_ON_ERROR))->toBe(json_encode($expected, JSON_THROW_ON_ERROR));
    })->with([false, true]);

    it('keeps the full intent of an Instance with a database server and no websocket app, while another Instance holds one', function (): void {
        $this->freezeTime();
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        $containers = app(InstanceContainer::class);
        $runtime = $containers->reserve($instance, $node);
        $services = containerDefaultNode('Services', '10.44.0.3', false);
        $services->roles()->create(['role' => RoleName::WebSocket, 'status' => 'active']);
        $server = DatabaseServer::query()->create(['slug' => 'catalog', 'driver' => 'pgsql', 'node_id' => $services->id, 'tag' => '18',
            'port' => 5434, 'root_password' => 'root-sentinel', 'status' => 'active']);
        $connection = DatabaseConnection::query()->forceCreate(['slug' => 'catalog', 'driver' => 'pgsql', 'node_id' => $services->id,
            'database_server_id' => $server->id, 'owner_instance_id' => $instance->id, 'host' => '10.44.0.3', 'port' => 5434,
            'database' => 'catalog', 'username' => 'catalog_reader', 'password' => 'catalog-secret']);
        DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $connection->id, 'prefix' => 'DB', 'network' => 'wireguard']);
        // Another Instance's private app opens nothing for this one.
        $other = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'other', 'checkout_path' => '/test/other']);
        $app = WebSocketApp::query()->create(['name' => 'tool', 'app_id' => '1001', 'key' => 'reverb-key', 'secret' => 'reverb-secret', 'credential_generation' => 1]);
        WebSocketAppTarget::query()->create(['websocket_app_id' => $app->id, 'instance_id' => $other->id]);

        $intent = $containers->intent($instance, $runtime);
        expect(json_encode($intent, JSON_THROW_ON_ERROR))
            ->toBe(json_encode(normalContainerSinceMain($instance, $runtime), JSON_THROW_ON_ERROR))
            ->and($intent)->not->toHaveKey('private_names')
            ->and(array_column($intent['bridge_endpoints'], 'port'))->toBe([5434]);
    });

    it('admits a normal default create with the same runtime, workspace path and captured container intent', function (): void {
        SwampNodeFixture::ensure();
        $this->freezeTime();
        $node = nestedSwitch(containerDefaultNode(), true);
        $operation = containerCreate(containerDefaultProject(), $node);
        $instance = Instance::query()->sole();
        $runtime = InstanceRuntime::query()->sole();
        // The create path adds its `steps` beside the container intent.
        $captured = containerIntentOf($operation);
        unset($captured['steps']);
        expect($runtime->incus_project)->toBe('orbit-instances')
            ->and($instance->checkout_path)->toBe('/var/lib/incus/storage-pools/orbit-instances/containers/orbit-instances_instance-'.$instance->id.'/rootfs/srv/workspace')
            ->and(json_encode($captured, JSON_THROW_ON_ERROR))
            ->toBe(json_encode(normalContainerSinceMain($instance, $runtime), JSON_THROW_ON_ERROR));
    });
});

/**
 * The frozen baseline with the one intended change since it: a default's deploy steps put Playwright's browsers beside its package
 * (InstanceContainer::BrowserEnvironment).
 *
 * @return array<string, mixed>
 */
function normalContainerSinceMain(Instance $instance, InstanceRuntime $runtime): array
{
    $expected = app(BaselineNormalInstanceContainer::class)->intent($instance, $runtime);
    $expected['environment'][] = 'PLAYWRIGHT_BROWSERS_PATH=0';

    return $expected;
}

describe('a switched-on Node', function (): void {
    it('prepares the nested project and keeps two spare maps; recorded nested runtimes add one each, on their Node only', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $other = nestedSwitch(containerDefaultNode('shark', '10.44.0.8'), true);
        $host = app(InstanceHostIntent::class)->for($node);
        expect(array_keys($host['nested']))->toBe(['project', 'image'])
            ->and($host['nested']['project'])->toBe('orbit-instances-nested')
            ->and($host['nested']['image']['alias'])->toStartWith('orbit-instance-nested-')
            ->and($host['project'])->toBe('orbit-instances')
            ->and($host['ids'])->toBe(13_172_736 + 2 * 101_000_000)
            ->and($host)->not->toHaveKey('runtime_projects');
        recordedNestedInstance(containerDefaultProject(), $node);
        recordedNestedInstance(otherProject('second'), $node);
        expect(app(InstanceHostIntent::class)->for($node)['ids'])->toBe(13_172_736 + 4 * 101_000_000)
            ->and(app(InstanceHostIntent::class)->for($other)['ids'])->toBe(13_172_736 + 2 * 101_000_000);
    });

    it('counts task container places from settings.task_vms.containers, never the VM places', function (): void {
        $node = containerDefaultNode();
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true,
            'task_vms' => taskVms(7, 5)]]);
        expect(app(InstanceHostIntent::class)->for($node->refresh())['ids'])->toBe(13_172_736 + (2 + 1 + 1) * 101_000_000 + 2 * 101_000_000);
    });

    it('keeps recorded nested runtimes in the ID demand and the folder refresh after the switch is off', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        recordedNestedInstance(containerDefaultProject(), $node);
        $host = app(InstanceHostIntent::class)->for(nestedSwitch($node, false));
        expect($host)->not->toHaveKey('nested')
            ->and($host['runtime_projects'])->toBe(['orbit-instances', 'orbit-instances-nested'])
            ->and($host['ids'])->toBe(13_172_736 + 101_000_000);
    });
});

it('validates a captured container Schedule run in either recorded project against the production workflow schema', function (bool $nested): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = $nested ? recordedNestedInstance($project, $node) : Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'default', 'checkout_path' => '/test/default']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false]);
    $runtime = $instance->runtime()->first() ?? app(InstanceContainer::class)->reserve($instance, $node);
    $schedule = Schedule::query()->create(['target_type' => 'instance', 'target_id' => $instance->id, 'host_node_id' => $node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 30, 'trigger' => 'swamp', 'desired_timer_state' => 'enabled', 'status' => 'active']);
    SwampNodeFixture::ensure();
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $sent) use (&$frame): void {
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame): array {
        return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
            'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'fixture']);
    $input = app(DirectOperation::class)->workflowInputs(app(RunScheduleAction::class)->admit($schedule));
    expect($input['intent']['container'])->toBe(['project' => $runtime->refresh()->incus_project, 'name' => $runtime->container_name]);
    $workflow = Yaml::parseFile(base_path('../../swamp/workflows/workflow-task-command-run.yaml'));
    $validate = new Process(['deno', 'eval', <<<'JS'
import Ajv from "npm:ajv@8.17.1";
import addFormats from "npm:ajv-formats@3.0.1";
const {schema, input} = JSON.parse(await new Response(Deno.stdin.readable).text());
const ajv = new Ajv({strict: false});
addFormats(ajv);
if (!ajv.validate(schema, input)) throw new Error(JSON.stringify(ajv.errors));
JS]);
    $validate->setInput(json_encode(['schema' => $workflow['inputs'], 'input' => $input], JSON_THROW_ON_ERROR));
    $validate->run();
    expect($validate->isSuccessful())->toBeTrue($validate->getErrorOutput())
        ->and($workflow['jobs'][0]['steps'][0]['task']['inputs']['argv'])->toContain('inputs.intent.container.project');
})->with([false, true]);

describe('the id map size', function (): void {
    it('records the full size for a new nested default and its copies, the converted size for copies made after the conversion, and none for a normal container', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $project = otherProject('sized', true);
        $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/sized/default']);
        $runtime = app(InstanceContainer::class)->reserve($default, $node);
        $old = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'old', 'checkout_path' => '/test/sized/old']);
        $oldRuntime = app(InstanceContainer::class)->reserve($old, $node, $default);
        expect($runtime->idmap_size)->toBe(101000000)->and($oldRuntime->idmap_size)->toBe(101000000)
            ->and(app(InstanceContainer::class)->intent($default, $runtime)['config']['security.idmap.size'])->toBe('101000000');

        // docs/reference/development-containers.md#convert-a-default: the record follows the operator's change on the Node and clears the seed.
        $runtime->update(['seed_commit' => str_repeat('a', 40)]);
        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $default->id, 'size' => '1165536'])->assertSuccessful();
        $runtime->refresh();
        expect($runtime->idmap_size)->toBe(1165536)->and($runtime->seed_commit)->toBeNull()
            ->and(app(InstanceContainer::class)->intent($default, $runtime)['config']['security.idmap.size'])->toBe('1165536');

        // After the next passed deployment's seed, a copy takes the converted size; the older copy keeps its own, so its start guard still matches.
        $runtime->update(['seed_commit' => str_repeat('b', 40)]);
        $new = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'new', 'checkout_path' => '/test/sized/new']);
        $newRuntime = app(InstanceContainer::class)->reserve($new, $node, $default);
        expect($newRuntime->idmap_size)->toBe(1165536)
            ->and(app(InstanceContainer::class)->intent($new, $newRuntime)['config']['security.idmap.size'])->toBe('1165536')
            ->and($oldRuntime->fresh()?->idmap_size)->toBe(101000000)
            ->and(app(InstanceContainer::class)->intent($old, $oldRuntime->fresh())['config']['security.idmap.size'])->toBe('101000000');

        $normal = Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/normal']);
        $normalRuntime = app(InstanceContainer::class)->reserve($normal, $node);
        expect($normalRuntime->idmap_size)->toBeNull()
            ->and(app(InstanceContainer::class)->intent($normal, $normalRuntime)['config'])->not->toHaveKey('security.idmap.size');
    });

    it('counts each nested container\'s recorded size in the Node\'s subordinate ID demand', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $before = app(InstanceHostIntent::class)->for($node)['ids'];
        $default = recordedNestedInstance(otherProject('demand', true), $node);
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['idmap_size' => 1165536]);
        recordedNestedInstance($default->project, $node, 'feature', $default);
        recordedNestedInstance(otherProject('legacy', true), $node);

        expect(app(InstanceHostIntent::class)->for($node)['ids'] - $before)->toBe(2 * 1165536 + 101000000);
    });

    it('refuses a size, Instance or moment the conversion record does not fit, and changes nothing', function (string $case, string $code): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $default = recordedNestedInstance(otherProject('refused', true), $node);
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('a', 40)]);
        $target = match ($case) {
            'a clone' => recordedNestedInstance($default->project, $node, 'feature', $default),
            'a normal default' => (function () use ($node): Instance {
                $normal = Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/normal']);
                app(InstanceContainer::class)->reserve($normal, $node);

                return $normal;
            })(),
            default => $default,
        };
        if ($case === 'a rollback under a small copy') {
            InstanceRuntime::query()->where('instance_id', $default->id)->update(['idmap_size' => 1165536]);
            recordedNestedInstance($default->project, $node, 'small', $default);
        }
        if ($case === 'an open Operation') {
            DB::table('operations')->insert(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $node->id,
                'status' => 'running', 'input' => '{}', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $size = match ($case) {
            'another size' => '65536',
            'a rollback under a small copy' => '101000000',
            default => '1165536',
        };
        // The size refusal names the floor: the inner range is the outer size minus 1,000,000 (development-containers.md#the-id-map-size).
        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $target->id, 'size' => $size])
            ->expectsOutputToContain($case === 'another size' ? $code.': The size must be 1165536 or 101000000. The inner range is the outer size minus 1,000,000, so no size below 1,065,536 works.' : $code)
            ->assertFailed();
        expect(InstanceRuntime::query()->where('instance_id', $default->id)->first(['idmap_size', 'seed_commit'])?->only(['idmap_size', 'seed_commit']))
            ->toBe(['idmap_size' => $case === 'a rollback under a small copy' ? 1165536 : 101000000, 'seed_commit' => str_repeat('a', 40)]);
    })->with([
        'another size' => ['another size', 'instance.idmap_size_invalid'],
        'a clone' => ['a clone', 'instance.idmap_size_not_nested_default'],
        'a normal default' => ['a normal default', 'instance.idmap_size_not_nested_default'],
        'an open Operation' => ['an open Operation', 'operation.target_busy'],
        'a rollback under a small copy' => ['a rollback under a small copy', 'instance.idmap_size_copies_smaller'],
    ]);

    it('warns in Doctor from the kept ID map read while a full-size nested container is on the Node, and not after its conversion', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        // Doctor observes only a Node whose SSH management is verified.
        DB::table('nodes')->where('id', $node->id)->update(['ssh_host_fingerprint' => 'SHA256:test']);
        $default = recordedNestedInstance(otherProject('doctor', true), $node);
        $issues = static fn (): array => array_values(array_filter(app(FactDoctor::class)->report([$node->refresh()], [DoctorFamily::Node])->toArray()['nodes'][0]['families'][0]['issues'],
            static fn (array $issue): bool => $issue['code'] === 'node.id_ranges_low'));
        DB::table('nodes')->where('id', $node->id)->update(['id_ranges' => json_encode(['full' => 1, 'small' => 10, 'read_at' => '2026-11-14T13:00:00+00:00'])]);
        expect($issues())->toHaveCount(1);
        DB::table('nodes')->where('id', $node->id)->update(['id_ranges' => json_encode(['full' => 2, 'small' => 3, 'read_at' => '2026-11-14T13:00:00+00:00'])]);
        expect($issues())->toHaveCount(1)->and($issues()[0]['observed'])->toBe('2 full-size, 3 normal slots free, read at 2026-11-14T13:00:00+00:00');
        DB::table('nodes')->where('id', $node->id)->update(['id_ranges' => json_encode(['full' => 2, 'small' => 4, 'read_at' => '2026-11-14T13:00:00+00:00'])]);
        expect($issues())->toBe([]);

        DB::table('nodes')->where('id', $node->id)->update(['id_ranges' => json_encode(['full' => 0, 'small' => 0, 'read_at' => '2026-11-14T13:00:00+00:00'])]);
        // An old copy keeps the full size but retakes no seed, so only the default's own size counts.
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('a', 40)]);
        $copy = recordedNestedInstance($default->project, $node, 'feature', $default);
        expect($issues())->toHaveCount(1);
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['idmap_size' => 1165536]);
        expect($copy->runtime()->value('idmap_size'))->toBe(101000000)->and($issues())->toBe([]);
    });

    it('fills the full size for every nested runtime that exists when the column comes, and none for a normal one', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $nested = recordedNestedInstance(otherProject('existing', true), $node);
        $normal = Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/normal']);
        app(InstanceContainer::class)->reserve($normal, $node);
        $migration = require database_path('migrations/2027_02_16_000000_add_idmap_size_to_instance_runtimes.php');
        $migration->down();
        $migration->up();

        expect(InstanceRuntime::query()->where('instance_id', $nested->id)->value('idmap_size'))->toBe(101000000)
            ->and(InstanceRuntime::query()->where('instance_id', $normal->id)->value('idmap_size'))->toBeNull();
    });
});
