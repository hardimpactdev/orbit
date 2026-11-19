<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Analytics\PlausibleProcess;
use App\Domain\GatewayReleases\GatewayReleaseHost;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Instances\InstanceEgress;
use App\Domain\Instances\InstanceFleetGateway;
use App\Domain\Instances\InstanceFolderPlan;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\NestedFleetLayout;
use App\Domain\Metrics\MetricsContainerPlan;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\Engine\GatewayBus;
use App\Infrastructure\MachineSoftware\CurrentRecipe;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Render the existing role credential types into protected native file resources. */
final readonly class NativeNodeRoleRuntimeInput
{
    /**
     * The CI runner's rendered runtime inputs, with no per-Operation data. The seed listener trusts the Incus client certificate
     * from the default's Node. With no sender, the converge removes the trust and its listener at the recorded address.
     *
     * @return array{proof_seed: array<string,mixed>, directories: list<array<string,string>>, files: list<array<string,string>>, units: list<array<string,string>>}
     */
    public function ciRunner(Node $node): array
    {
        $sender = app(ProofSeeds::class)->sender($node);
        $seed = ['present' => $sender !== null, 'listen' => ValidatedData::string($node->wireguard_ip).':'.ProofSeeds::ListenerPort,
            'trust' => ProofSeeds::TrustName, 'project' => InstancePlacement::NestedProject,
            'sender' => $sender === null ? null : ['node_id' => $sender->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($sender)]];

        return ['proof_seed' => $seed, ...app(CiRunnerStateFiles::class)->runtime()];
    }

    /** @param array<string,mixed> $snapshot
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function capture(Node $node, NodeRole $assignment, array $snapshot, ?Operation $operation, bool $converge = false): array
    {
        $runtime = ['directories' => [], 'files' => [], 'secret_files' => [], 'units' => [], 'probes' => [], 'groups' => [], 'users' => [],
            'websocket' => null, 'metrics' => null, 'analytics' => null, 'gateway_dns' => null, 'gateway' => null,
            'swamp' => $assignment->role === RoleName::Swamp ? $snapshot['runtime'] : null,
            'ci_host' => $assignment->role === RoleName::CiRunner];
        $copies = [];
        if ($assignment->role === RoleName::CiRunner) {
            $runtime = array_replace($runtime, $this->ciRunner($node));
        }
        if ($assignment->role === RoleName::Gateway) {
            $gateway = app(GatewayRuntimeFiles::class)->render($node);
            $runtime['gateway'] = ['pool' => GatewayRuntimeFiles::Pool, 'release_units' => GatewayRuntimeFiles::ReleaseUnits, 'account' => $node->user,
                'environment' => GatewayReleaseLayout::fromConfig()->sharedPath().'/gateway.env', 'retired_settings' => GatewayRuntimeFiles::RetiredSettings,
                'tmpfiles' => GatewayRuntimeFiles::RuntimeTmpfiles,
                // The machine bus's server binary, pinned by its tarball's SHA-256, only on a Gateway that runs the bus (ADR 0271).
                ...(GatewayBus::enabled() ? ['nats' => GatewayBus::artifact()] : [])];
            if (GatewayReleaseHost::inventory($node) !== null) {
                $runtime['gateway']['inventory'] = GatewayReleaseLayout::fromConfig()->containerInventoryPath();
                $runtime['gateway']['container'] = true;
                $runtime['gateway']['retired_fpm'] = GatewayReleaseHost::retiredFpm($node);
            }
            $runtime['directories'] = [...$runtime['directories'], ...$gateway['directories']];
            $runtime['files'] = [...$runtime['files'], ...$gateway['files']];
            $runtime['units'] = [...$runtime['units'], ...$gateway['units']];
        }
        if ($assignment->role === RoleName::Gateway && $snapshot['gateway_dns'] !== null) {
            $dns = ValidatedData::object($snapshot['gateway_dns']);
            $domain = ValidatedData::string($dns['domain']);
            $address = ValidatedData::string($dns['address']);
            $runtime['gateway_dns'] = ['unit' => "# Managed by Orbit.\n[Unit]\nDescription=Orbit Gateway DNS routing\nAfter=wg-quick@orbit.service\nConditionPathExists=/sys/class/net/orbit\nConditionPathExists=!/etc/wireguard/orbit.dns-link\n\n[Service]\nType=oneshot\nExecStart=/usr/bin/resolvectl domain orbit ~{$domain}\nExecStart=/usr/bin/resolvectl default-route orbit false\nExecStart=/usr/bin/resolvectl dns orbit {$address}\n\n[Install]\nWantedBy=wg-quick@orbit.service\n"];
            foreach (['listener', 'withdraw'] as $action) {
                if (isset($dns[$action])) {
                    $runtime['gateway_dns'][$action] = ValidatedData::object($dns[$action]);
                }
            }
            $runtime['files'][] = ['path' => '/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf',
                'ensure' => 'absent', 'content' => '', 'owner' => 'root', 'group' => 'root', 'mode' => '0644'];
        }
        // Only the role that runs the branch on the Node gets the key: `app-dev` on an app-dev Node, `ci-runner` on a CI-only
        // Node. So the intent of every other Node and role stays as it was.
        $hosts = app(InstanceHostIntent::class);
        // A desired-state read cannot allocate a bridge. Admission prepares it before capturing the applied plan.
        $instances = $hosts->role($node) === $assignment->role && ($operation !== null || $node->instance_bridge !== null)
            ? $hosts->for($node) : null;
        if ($assignment->role === RoleName::AppProd && $node->instance_bridge !== null) {
            $recipe = app(CurrentRecipe::class)->currentRecipe();
            $architecture = MachineRecipe::architecture($node->architecture);
            $incus = $architecture === null ? null : $recipe->incus($architecture);
            if ($incus === null || $recipe->instances === null) {
                throw new ResourceOperationException('deployment.node_runtime_missing', 'No production container runtime is published for this Node.', 409);
            }
            $subnet = Ipv4Subnet::from($node->instance_bridge);
            $gateway = $subnet->usableRange()[0];
            $nested = InstanceFleetGateway::holds() && $node->public_ssh_host === NestedFleetLayout::Layout['app-prod']['address'];
            $parent = $nested ? InstanceFleetGateway::egressProxy() : null;
            if ($nested && $parent === null) {
                throw new ResourceOperationException('instance.fleet_egress_unavailable', 'The nested production Node needs its fleet egress proxy.', 409);
            }
            $runtime['production_releases'] = [
                'gateway' => $gateway,
                'cidr' => $node->instance_bridge,
                'egress' => InstanceEgress::for(InstanceHostIntent::Network, $gateway, $node->instance_bridge, app(PeerDnsPolicy::class)->domains(), $parent),
                'node_id' => $node->id,
                'nested' => $nested,
                'incus' => $incus,
                'folder' => InstanceFolderPlan::for($recipe->instances, InstanceFolderPlan::shift($node), '/opt/orbit-production-runtime')['runtime'],
            ];
        }
        if ($instances !== null) {
            $runtime['instances'] = $instances;
        }
        if (! in_array($assignment->role, [RoleName::WebSocket, RoleName::Metrics, RoleName::Analytics], true)) {
            return ['intent' => $runtime, 'execution_secrets' => $copies];
        }
        $source = app(NodeRoleRuntimeSource::class);
        $material = $operation === null ? [] : ValidatedData::object($source->resolveRoleMaterial($snapshot, $node->id)->value);
        $secret = function (string $name, string $materialKey) use (&$copies, $operation, $material): string {
            $field = str_replace('-', '_', $name);
            if ($operation !== null) {
                $copies[$field] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
                    'node-role-operation-'.$operation->id.'-'.$name, ValidatedData::string($material[$materialKey]));
            }

            return $field;
        };
        if ($assignment->role === RoleName::WebSocket) {
            $spec = ValidatedData::object($snapshot['runtime']);
            $path = ValidatedData::string($spec['install_path']);
            $release = $path.'.releases/'.hash('sha256', ValidatedData::string($spec['repository'])."\n".ValidatedData::string($spec['ref']));
            $runtime['directories'] = [['path' => dirname($path), 'owner' => 'root', 'group' => 'root', 'mode' => '0755'],
                ['path' => $path.'.releases', 'owner' => $node->user, 'group' => $node->user, 'mode' => '0755']];
            $runtime['secret_files'] = [['path' => $release.'/.env', 'owner' => $node->user, 'group' => $node->user, 'mode' => '0600',
                'secret' => $secret('websocket-environment', 'websocket_environment')]];
            $runtime['units'] = [['name' => 'orbit-websocket.service', 'unit' => app(WebSocketServiceUnitRenderer::class)->render($path, $node->user, $node->user, ValidatedData::integer($spec['port']))]];
            $runtime['websocket'] = ['repository' => $spec['repository'], 'ref' => $spec['ref'], 'release' => $release, 'path' => $path,
                'user' => $node->user, 'port' => $spec['port'], 'health_url' => 'http://127.0.0.1:'.ValidatedData::integer($spec['port']).'/'];
            $runtime['probes'][] = ['url' => $runtime['websocket']['health_url'], 'require_success' => false];
        } elseif ($assignment->role === RoleName::Metrics) {
            $runtime['groups'] = [['name' => 'orbit-grafana', 'gid' => 472]];
            $runtime['users'] = [['name' => 'orbit-grafana', 'uid' => 472, 'gid' => 472]];
            $passwordPath = NodeRoleRuntimeSource::MetricsPasswordPath;
            $files = $operation === null ? $source->metricsFiles($node, ValidatedData::object($snapshot['runtime']))
                : array_diff_key(ValidatedData::stringMap($material['configuration']), [$passwordPath => true]);
            $public = [];
            foreach ($files as $path => $content) {
                $public[] = ['path' => $path, 'content' => $content];
                $runtime['files'][] = ['path' => $path, 'content' => $content, 'owner' => 'root', 'group' => 'root', 'mode' => '0644'];
            }
            $runtime['secret_files'][] = ['path' => $passwordPath, 'owner' => 'orbit-grafana', 'group' => 'orbit-grafana', 'mode' => '0600',
                'secret' => $secret('metrics-password', 'password')];
            $paths = array_values(array_unique(array_map(dirname(...), [...array_keys($files), $passwordPath])));
            usort($paths, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));
            $runtime['directories'] = array_map(static fn (string $path): array => ['path' => $path, 'owner' => 'root', 'group' => 'root', 'mode' => '0755'], $paths);
            $runtime['metrics'] = ['assignment_id' => $assignment->id, 'address' => $node->wireguard_ip, 'files' => $public,
                'plan' => app(MetricsContainerPlan::class)->for($assignment->id, (string) $node->wireguard_ip, $files)];
            $runtime['probes'] = [['url' => 'http://127.0.0.1:9090/-/ready', 'require_success' => true],
                ['url' => 'http://'.$node->wireguard_ip.':3000/api/health', 'require_success' => true]];
        } else {
            $spec = ValidatedData::object($snapshot['runtime']);
            if ($converge || $operation?->kind === 'node.role_converge') {
                $clickhouse = Node::query()->findOrFail(ValidatedData::integer(ValidatedData::object($spec['clickhouse'])['node_id']));
                $runtime['analytics'] = ['process_id' => ValidatedData::object($spec['installation'])['process_id'],
                    'intent' => [], 'initialize' => [], 'clickhouse_node_id' => $clickhouse->id,
                    'clickhouse_ssh' => app(SwampSshExecutionInput::class)->forNode($clickhouse), 'health_url' => $spec['health_url']];
                $runtime['probes'][] = ['url' => $spec['health_url'], 'require_success' => true];

                return ['intent' => $runtime, 'execution_secrets' => $copies];
            }
            if ($operation === null) {
                throw new \LogicException('A role installation requires an admitted Operation.');
            }
            $process = Process::query()->whereKey(ValidatedData::object($spec['installation'])['process_id'])->where('owner_type', Node::class)
                ->where('owner_id', $node->id)->where('name', PlausibleProcess::NAME)->firstOrFail();
            $input = app(ProcessOperationAdmission::class)->inputFor($process, 'create');
            $bundle = app(ProcessLifecycleInput::class)->forRenderedInstallation($input,
                ValidatedData::object($material['installation']), $node,
                'process-parent-operation-'.$operation->id.'-node-'.$node->id.'-member-'.$process->id);
            if (! isset($bundle['execution_secrets']['environment'])) {
                throw new ResourceOperationException('analytics.settings_missing', 'Analytics requires its captured environment.', 409);
            }
            $copies['analytics_environment'] = $bundle['execution_secrets']['environment'];
            $installation = ValidatedData::object($bundle['intent']['installation']);
            $definition = ValidatedData::object($installation['definition']);
            $environmentPath = ValidatedData::string($installation['environment_path']);
            $runtime['directories'][] = ['path' => dirname($environmentPath), 'owner' => 'root', 'group' => 'root', 'mode' => '0755'];
            $runtime['secret_files'][] = ['path' => $environmentPath, 'owner' => 'root', 'group' => 'root', 'mode' => '0600', 'secret' => 'analytics_environment'];
            $volumes = [];
            foreach (RouteExecutionProof::objects($definition['mounts']) as $mount) {
                $volumes = [...$volumes, '--volume', ValidatedData::string($mount['source']).':'.ValidatedData::string($mount['target']).(($mount['read_only'] ?? false) === true ? ':ro' : '')];
            }
            $initialize = [];
            foreach (['createdb', 'migrate'] as $phase) {
                $name = 'orbit-analytics-operation-'.$operation->id.'-'.$phase;
                $initialize[] = ['name' => $name, 'image' => $definition['image'], 'argv' => [
                    'timeout', '900', 'docker', 'run', '--rm', '--name', $name, '--env-file', $environmentPath,
                    ...$volumes, ValidatedData::string($definition['image']), '/entrypoint.sh', 'db', $phase]];
            }
            $clickhouse = Node::query()->findOrFail(ValidatedData::integer(ValidatedData::object($spec['clickhouse'])['node_id']));
            $runtime['analytics'] = ['process_id' => $process->id, 'intent' => $bundle['intent'],
                'initialize' => $initialize, 'clickhouse_node_id' => $clickhouse->id,
                'clickhouse_ssh' => app(SwampSshExecutionInput::class)->forNode($clickhouse), 'health_url' => $spec['health_url']];
            $runtime['probes'][] = ['url' => $spec['health_url'], 'require_success' => true];
        }

        return ['intent' => $runtime, 'execution_secrets' => $copies];
    }
}
