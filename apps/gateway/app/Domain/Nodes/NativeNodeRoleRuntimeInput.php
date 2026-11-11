<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Analytics\PlausibleProcess;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Metrics\MetricsContainerPlan;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\PrivateDnsAnswerCatalog;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Render the existing role credential types into protected native file resources. */
final readonly class NativeNodeRoleRuntimeInput
{
    /** @param array<string,mixed> $snapshot
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function capture(Node $node, NodeRole $assignment, array $snapshot, Operation $operation, bool $converge = false): array
    {
        $runtime = ['directories' => [], 'files' => [], 'secret_files' => [], 'units' => [], 'probes' => [], 'groups' => [], 'users' => [],
            'websocket' => null, 'metrics' => null, 'analytics' => null, 'gateway_dns' => null, 'gateway' => null,
            'swamp' => $assignment->role === RoleName::Swamp ? $snapshot['runtime'] : null,
            'ci_host' => $assignment->role === RoleName::CiRunner];
        $copies = [];
        if ($assignment->role === RoleName::Gateway) {
            $gateway = app(GatewayRuntimeFiles::class)->render($node);
            $runtime['gateway'] = ['pool' => GatewayRuntimeFiles::Pool, 'release_units' => GatewayRuntimeFiles::ReleaseUnits, 'account' => $node->user,
                'environment' => GatewayReleaseLayout::fromConfig()->sharedPath().'/gateway.env', 'retired_settings' => GatewayRuntimeFiles::RetiredSettings,
                'tmpfiles' => GatewayRuntimeFiles::RuntimeTmpfiles];
            $runtime['directories'] = [...$runtime['directories'], ...$gateway['directories']];
            $runtime['files'] = [...$runtime['files'], ...$gateway['files']];
            $runtime['units'] = [...$runtime['units'], ...$gateway['units']];
        }
        if ($assignment->role === RoleName::Gateway && $snapshot['gateway_dns'] !== null) {
            $dns = ValidatedData::object($snapshot['gateway_dns']);
            $domain = ValidatedData::string($dns['domain']);
            $upstream = ValidatedData::string($dns['address']);
            $address = ValidatedData::string($node->wireguard_ip);
            $runtime['gateway_dns'] = ['unit' => "# Managed by Orbit.\n[Unit]\nDescription=Orbit Gateway DNS routing\nAfter=wg-quick@orbit.service\nConditionPathExists=/sys/class/net/orbit\nConditionPathExists=!/etc/wireguard/orbit.dns-link\n\n[Service]\nType=oneshot\nExecStart=/usr/bin/resolvectl domain orbit ~{$domain}\nExecStart=/usr/bin/resolvectl default-route orbit false\nExecStart=/usr/bin/resolvectl dns orbit {$address}\n\n[Install]\nWantedBy=wg-quick@orbit.service\n"];
            $hub = $upstream === $address || $node->roles()->where('role', RoleName::Vpn)->exists();
            $publication = $hub ? app(NodeConvergeInput::class)->dns() : ['records' => '', 'catalog' => json_encode([
                'requesters' => [], ...(new PrivateDnsAnswerCatalog([ReservedPrivateHostname::Gateway => $address], []))->toPublished(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"];
            // Both roles converge the same footprint and fixed socket, including the first bootstrap before VPN publication.
            $runtime['gateway_dns']['listener'] = app(NodeConvergeInput::class)->privateDns($node, [
                'listener' => app(NodePrivateDnsListenerFootprint::class)->input($node, $hub ? null : $upstream.':53'),
                'publication' => $publication,
            ]);
            $runtime['files'][] = ['path' => '/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf',
                'ensure' => 'absent', 'content' => '', 'owner' => 'root', 'group' => 'root', 'mode' => '0644'];
        }
        // Only a Node on the build list gets the key, so the intent of every other app-dev Node stays as it was.
        $instances = $assignment->role === RoleName::AppDev ? app(InstanceHostIntent::class)->for($node) : null;
        if ($instances !== null) {
            $runtime['instances'] = $instances;
        }
        if (! in_array($assignment->role, [RoleName::WebSocket, RoleName::Metrics, RoleName::Analytics], true)) {
            return ['intent' => $runtime, 'execution_secrets' => $copies];
        }
        $material = ValidatedData::object(app(NodeRoleRuntimeSource::class)->resolveRoleMaterial($snapshot, $node->id)->value);
        $secret = function (string $name, string $content) use (&$copies, $operation): string {
            $field = str_replace('-', '_', $name);
            $copies[$field] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'node-role-operation-'.$operation->id.'-'.$name, $content);

            return $field;
        };
        if ($assignment->role === RoleName::WebSocket) {
            $spec = ValidatedData::object($snapshot['runtime']);
            $path = ValidatedData::string($spec['install_path']);
            $release = $path.'.releases/'.hash('sha256', ValidatedData::string($spec['repository'])."\n".ValidatedData::string($spec['ref']));
            $runtime['directories'] = [['path' => dirname($path), 'owner' => 'root', 'group' => 'root', 'mode' => '0755'],
                ['path' => $path.'.releases', 'owner' => $node->user, 'group' => $node->user, 'mode' => '0755']];
            $runtime['secret_files'] = [['path' => $release.'/.env', 'owner' => $node->user, 'group' => $node->user, 'mode' => '0600',
                'secret' => $secret('websocket-environment', ValidatedData::string($material['websocket_environment']))]];
            $runtime['units'] = [['name' => 'orbit-websocket.service', 'unit' => app(WebSocketServiceUnitRenderer::class)->render($path, $node->user, $node->user, ValidatedData::integer($spec['port']))]];
            $runtime['websocket'] = ['repository' => $spec['repository'], 'ref' => $spec['ref'], 'release' => $release, 'path' => $path,
                'user' => $node->user, 'port' => $spec['port'], 'health_url' => 'http://127.0.0.1:'.ValidatedData::integer($spec['port']).'/'];
            $runtime['probes'][] = ['url' => $runtime['websocket']['health_url'], 'require_success' => false];
        } elseif ($assignment->role === RoleName::Metrics) {
            $runtime['groups'] = [['name' => 'orbit-grafana', 'gid' => 472]];
            $runtime['users'] = [['name' => 'orbit-grafana', 'uid' => 472, 'gid' => 472]];
            $files = ValidatedData::stringMap($material['configuration']);
            $public = [];
            foreach ($files as $path => $content) {
                if ($path === '/etc/orbit/metrics/grafana/admin-password') {
                    $runtime['secret_files'][] = ['path' => $path, 'owner' => 'orbit-grafana', 'group' => 'orbit-grafana', 'mode' => '0600',
                        'secret' => $secret('metrics-password', $content)];
                } else {
                    $public[] = ['path' => $path, 'content' => $content];
                    $runtime['files'][] = ['path' => $path, 'content' => $content, 'owner' => 'root', 'group' => 'root', 'mode' => '0644'];
                }
            }
            $paths = array_values(array_unique(array_map(dirname(...), array_keys($files))));
            usort($paths, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));
            $runtime['directories'] = array_map(static fn (string $path): array => ['path' => $path, 'owner' => 'root', 'group' => 'root', 'mode' => '0755'], $paths);
            $runtime['metrics'] = ['assignment_id' => $assignment->id, 'address' => $node->wireguard_ip, 'files' => $public,
                'plan' => app(MetricsContainerPlan::class)->for($assignment->id, (string) $node->wireguard_ip, ValidatedData::stringMap(array_column($public, 'content', 'path')))];
            $runtime['probes'] = [['url' => 'http://127.0.0.1:9090/-/ready', 'require_success' => true],
                ['url' => 'http://'.$node->wireguard_ip.':3000/api/health', 'require_success' => true]];
        } else {
            $spec = ValidatedData::object($snapshot['runtime']);
            if ($converge || $operation->kind === 'node.role_converge') {
                $clickhouse = Node::query()->findOrFail(ValidatedData::integer(ValidatedData::object($spec['clickhouse'])['node_id']));
                $runtime['analytics'] = ['process_id' => ValidatedData::object($spec['installation'])['process_id'],
                    'intent' => [], 'initialize' => [], 'clickhouse_node_id' => $clickhouse->id,
                    'clickhouse_ssh' => app(SwampSshExecutionInput::class)->forNode($clickhouse), 'health_url' => $spec['health_url']];
                $runtime['probes'][] = ['url' => $spec['health_url'], 'require_success' => true];

                return ['intent' => $runtime, 'execution_secrets' => $copies];
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
