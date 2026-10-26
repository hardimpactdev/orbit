<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Fleet\FleetNodeVisitor;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\GatewayReleases\GatewayReleaseInventory;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Domain\Nodes\RoleName;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Tests\Support\FakeAgentCommandRunner;

/** Nodes, roles, and fakes for the fleet rollout tests. */
final class FleetFixtures
{
    public const string Commit = '0123456789abcdef0123456789abcdef01234567';

    private static int $address = 10;

    private static int $taskVms = 0;

    private static ?FakeAgentCommandRunner $releaseAgents = null;

    private static ?string $currentSha = null;

    private static ?string $runningSha = null;

    /** @var array<string, string> */
    private static array $commits = [];

    /** Points the Gateway checkout at a temporary in-place checkout: no release layout, no release records. */
    public static function inPlace(): string
    {
        $base = sys_get_temp_dir().'/orbit-fleet-layout-'.bin2hex(random_bytes(4));
        mkdir($base.'/orbit/apps/gateway', 0755, true);
        Config::set('orbit.gateway_checkout', $base.'/orbit/apps/gateway');
        self::$currentSha = null;
        self::$runningSha = null;
        self::$commits = [];
        self::$releaseAgents = new FakeAgentCommandRunner()->answer('gateway.inspect-releases', static fn (array $input): array => [
            'current' => self::$currentSha === null ? null : substr(self::$currentSha, 0, 12),
            'state' => self::$currentSha === null ? 'in_place' : 'adopted',
            'superseded' => $input['running_application'] !== null && self::$runningSha !== null && self::$currentSha !== self::$runningSha,
            'commits' => self::$commits, 'incomplete' => [], 'retained' => array_map(strval(...), array_keys(self::$commits)),
        ]);
        app()->instance(GatewayReleaseObserver::class, new class(self::$releaseAgents) implements GatewayReleaseObserver
        {
            public function __construct(private FakeAgentCommandRunner $agents) {}

            public function inspect(GatewayReleaseLayout $layout, ?string $runningApplication = null): GatewayReleaseInventory
            {
                return GatewayReleaseInventory::fromAgent($this->agents->run(new Node()->forceFill(['name' => 'gateway']), 'gateway.inspect-releases', [
                    'checkout' => $layout->applicationPath, 'running_application' => $runningApplication,
                ]));
            }
        });
        app()->instance(FleetServingRelease::class, new FleetServingRelease($base.'/orbit/apps/gateway'));

        return $base;
    }

    /** Turns the temporary checkout into the release layout serving the commit's release. */
    public static function adopt(string $commit): void
    {
        self::$currentSha = $commit;
        self::$commits[substr($commit, 0, 12)] = $commit;
        $base = dirname(Config::string('orbit.gateway_checkout'), 3);
        $id = substr($commit, 0, 12);
        @mkdir($base.'/releases/'.$id.'/apps/gateway', 0755, true);
        file_put_contents($base.'/releases/'.$id.'/REVISION', $commit."\n");

        if (is_link($base.'/orbit')) {
            unlink($base.'/orbit');
        } elseif (is_dir($base.'/orbit')) {
            exec('rm -rf '.escapeshellarg($base.'/orbit'));
        }

        symlink('releases/'.$id, $base.'/orbit');
    }

    public static function unreadable(): void
    {
        self::$releaseAgents?->refuse('gateway.inspect-releases', 'gateway.release_layout_invalid');
    }

    /** Runs this process as if from the release of the commit, as the unit does after a release. */
    public static function runFrom(string $commit): void
    {
        self::$runningSha = $commit;
        $base = dirname(Config::string('orbit.gateway_checkout'), 3);
        app()->instance(FleetServingRelease::class, new FleetServingRelease($base.'/releases/'.substr($commit, 0, 12).'/apps/gateway'));
    }

    /** @param list<RoleName> $roles */
    public static function node(string $name, array $roles = [], string $platform = 'linux', bool $managed = true, LifecycleStatus $status = LifecycleStatus::Active): Node
    {
        // The counter outlives each test in a parallel worker; keep the last octet a valid host address (11-250).
        $address = 11 + (self::$address++ - 10) % 240;
        $node = Node::query()->create([
            'name' => $name,
            'status' => $status,
            'platform' => $platform,
            'architecture' => $platform === 'macos' ? 'arm64' : 'x86_64',
            'public_ssh_host' => '203.0.113.'.$address,
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.'.$address,
            'ssh_host_key_type' => $managed ? 'ssh-ed25519' : null,
            'ssh_host_key' => $managed ? 'ssh-ed25519 AAAA'.$name : null,
            'ssh_host_fingerprint' => $managed ? 'SHA256:'.$name : null,
        ]);

        foreach ($roles as $role) {
            NodeRole::query()->create(['node_id' => $node->id, 'role' => $role, 'status' => LifecycleStatus::Active]);
        }

        return $node->load('roles');
    }

    /** A live task VM: an active, managed `app-dev` Node that its UpCloud task VM row owns (ADR 0200). */
    public static function taskVm(): Node
    {
        $name = 'tvm-'.++self::$taskVms;
        $project = Project::query()->firstOrCreate(['slug' => 'fleet-task-vms'], ['name' => 'Fleet task VMs', 'repository_url' => 'https://github.com/acme/fleet.git']);
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => $name, 'brief' => $name, 'status' => 'running']);
        $node = self::node($name, [RoleName::AppDev]);
        TaskVm::query()->create([
            'group_id' => $group->id, 'node_id' => $node->id, 'provider' => 'upcloud', 'name' => $name,
            'state' => TaskVmState::Ready, 'wireguard_ip' => $node->wireguard_ip, 'pi_token' => 'pi-token',
        ]);

        return $node->load('roles');
    }

    /** The requested delivery and typed final verification for recipe tests. */
    public static function recipeDelivery(FakeAgentCommandRunner $agents, bool $available = true): void
    {
        Config::set('app.version', self::Commit);
        app()->instance(DesiredFleetState::class, new DesiredFleetState(
            new FakeReleaseHistory,
            new FakeCliReleaseCatalog($available),
            new Repository(new ArrayStore),
            app(AgentDelivery::class),
        ));
        $agents->answer('recipe.verify-delivery', static fn (array $input): array => [
            'identity' => $input['identity'], 'agent_sha256' => $input['agent_sha256'], 'cli_version' => $input['cli_version'],
        ]);
    }

    /**
     * Binds a published CLI release, a fake Node visitor, the alert recorder, and units, with the rollout on.
     *
     * @param  array<int, NodeFootprintArtifact>  $artifacts
     * @return array{visitor: FakeFleetNodeVisitor, catalog: FakeCliReleaseCatalog, alerts: RecordingReleaseAlertNotifier, units: FakeFleetConvergeUnits}
     */
    public static function bind(array $artifacts = [], bool $published = true): array
    {
        Config::set('app.version', self::Commit);
        Config::set('fleet.rollout', true);
        self::inPlace();
        $visitor = new FakeFleetNodeVisitor;
        $catalog = new FakeCliReleaseCatalog($published);
        $alerts = new RecordingReleaseAlertNotifier;
        $units = new FakeFleetConvergeUnits;
        app()->instance(ReleaseHistory::class, new FakeReleaseHistory);
        app()->instance(CliReleaseCatalog::class, $catalog);
        app()->instance(FleetNodeVisitor::class, $visitor);
        app()->instance(ReleaseAlertNotifier::class, $alerts);
        app()->instance(FleetConvergeUnits::class, $units);
        app()->instance(NodeFootprint::class, new NodeFootprint(app(AgentDelivery::class), $artifacts));
        // Keep agent versions out of the shared file store.
        app()->instance(AgentReportedVersions::class, new AgentReportedVersions(new Repository(new ArrayStore)));

        return ['visitor' => $visitor, 'catalog' => $catalog, 'alerts' => $alerts, 'units' => $units];
    }
}
