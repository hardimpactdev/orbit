<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeSettingsData;
use App\Domain\Fleet\NodeFootprint as DesiredNodeFootprint;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Fleet\Footprint\PhpRuntimeFootprintArtifact;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Role-owned resources only. Node convergence owns the observer and CLI. */
final readonly class NativeNodeRoleAddInput
{
    /** @param array<string,mixed> $snapshot
     * @param  list<array<string,mixed>>  $before
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>,participant_node_ids:list<int>}
     */
    public function capture(Node $node, NodeRole $assignment, array $snapshot, array $before, Operation $operation, bool $converge = false): array
    {
        if ($assignment->node_id !== $node->id || ! $assignment->exists) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Role resources require their admitted assignment.', 422);
        }
        $role = $assignment->role;
        $packages = app(NodeBootstrapPackageCatalog::class)->forRole($node, $role);
        $caddy = in_array('caddy', $packages, true);
        $services = [...($caddy ? ['caddy.service'] : []),
            ...(in_array('docker.io', $packages, true) ? ['docker.service'] : [])];
        if ($role === RoleName::CiRunner) {
            $services[] = 'incus.socket';
        }

        $home = $node->user === 'root' ? '/root' : '/home/'.$node->user;
        $php = in_array($role, [RoleName::AppDev, RoleName::AppProd], true)
            ? app(PhpRuntimeFootprintArtifact::class)->inputs($node) : [];
        $directories = [];
        $files = [];
        $managers = [];
        $bun = null;
        $phpPackages = [];
        if (in_array($role, [RoleName::AppDev, RoleName::AppProd], true)) {
            foreach ([ToolManagerName::Vp, ToolManagerName::Composer] as $manager) {
                $managers[] = ['manager' => $manager->value, 'platform' => $node->platform,
                    'architecture' => MachineArchitecture::normalize($node->architecture), 'user' => $node->user,
                    'setup' => app(ToolManagerIntent::class)->nativeInput($node, $manager)];
            }
            $recipe = new RecipeLoader()->load();
            $build = PhpBuildInstaller::input($recipe, array_map(static fn (array $configuration): string => ValidatedData::string($configuration['version']), $php), $role === RoleName::AppDev,
                extras: 'keep', fpm: 'all', opentelemetry: true);
            $phpPackages = $this->phpPackages($build);
            $directories[] = ['path' => '/var/cache/orbit/role-php', 'owner' => 'root', 'group' => 'root', 'mode' => '0755'];
            $bunPin = $recipe->tools['bun']->forArchitecture('amd64');
            $bunArtifact = $bunPin->artifacts['amd64'];
            $bunRoot = '/opt/orbit/bun/releases/'.$bunArtifact->digest;
            $bun = ['url' => $bunArtifact->location, 'checksum' => $bunArtifact->algorithm.':'.$bunArtifact->digest,
                'path' => '/var/cache/orbit/role-bun/'.$bunArtifact->digest.'.zip', 'dest' => $bunRoot,
                'binary' => $bunRoot.'/bun-linux-x64/bun', 'version' => $bunPin->version, 'user' => $node->user];
            foreach (['/var/cache/orbit/role-bun', '/opt/orbit/bun', '/opt/orbit/bun/bin', '/opt/orbit/bun/releases', $bunRoot] as $path) {
                $directories[] = ['path' => $path, 'owner' => 'root', 'group' => 'root', 'mode' => '0755'];
            }
        }
        if ($role === RoleName::Vpn) {
            $build = PhpBuildInstaller::input(new RecipeLoader()->load(), [NodePrivateDnsListenerFootprint::PhpVersion], false,
                extras: 'keep', fpm: 'none', opentelemetry: false);
            $phpPackages = $this->phpPackages($build);
            $directories[] = ['path' => '/var/cache/orbit/role-php', 'owner' => 'root', 'group' => 'root', 'mode' => '0755'];
        }
        if ($role === RoleName::AppDev) {
            $storage = app(ConfiguredStoragePathValidator::class)->validateEffective(
                NodeSettingsData::from($node->settings ?? []), $node, new ManagedUserAccount($node->user, $node->user, $home));
            $directories[] = ['path' => $storage->value, 'owner' => $node->user, 'group' => $node->user, 'mode' => '0755'];
            array_push($directories, ...AppDevRuntimeFootprint::directories());
            array_push($files, ...AppDevRuntimeFootprint::files());
        }
        foreach ($php as $configuration) {
            $services[] = 'php'.ValidatedData::string($configuration['version']).'-fpm.service';
        }
        if ($caddy) {
            $directories[] = ['path' => '/etc/systemd/system/caddy.service.d', 'owner' => 'root', 'group' => 'root', 'mode' => '0755'];
            $files[] = ['path' => '/etc/systemd/system/caddy.service.d/orbit-vpn.conf',
                'content' => "# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n",
                'owner' => 'root', 'group' => 'root', 'mode' => '0644'];
        }
        $certificates = ValidatedData::object($snapshot['certificates']);
        $leafIdentity = static fn (array $leaf): array => ['node_id' => ValidatedData::integer($leaf['node_id']), 'scope' => ValidatedData::string($leaf['scope'])];
        $prospective = ['leaves' => array_map($leafIdentity, RouteExecutionProof::objects($certificates['leaves'])),
            'withdrawals' => array_map($leafIdentity, RouteExecutionProof::objects($certificates['withdrawals']))];
        $publications = app(RouteRenderContext::class)->renderRoleCertificates($prospective,
            fn (): array => app(NativeNodeRoleRemovalInput::class)->publications($node, $role));
        $previous = array_column($before, null, 'node_id');
        $publications = array_map(static fn (array $publication): array => [
            'node_id' => $publication['node_id'], 'connection' => $publication['connection'], 'host_keys' => $publication['host_keys'],
            'before' => ['php' => $previous[ValidatedData::integer($publication['node_id'])]['php'] ?? null, 'dns' => $previous[ValidatedData::integer($publication['node_id'])]['dns'] ?? null],
            'after' => ['caddy' => $publication['caddy'], 'php' => $publication['php'], 'dns' => $publication['dns']],
        ], $publications);

        $runtime = app(NativeNodeRoleRuntimeInput::class)->capture($node, $assignment, $snapshot, $operation, $converge);
        $desired = clone $node;
        $desired->setRelation('roles', $node->roles->map(static fn (NodeRole $member): NodeRole => $member->id === $assignment->id
            ? (clone $member)->forceFill(['status' => LifecycleStatus::Active]) : $member));
        $artifactDigests = [];
        foreach (app(DesiredNodeFootprint::class)->expected($desired)->artifacts as $name => $digest) {
            if (($name === 'caddy-package' && $caddy)
                || ($name === 'php-runtime' && $php !== [])
                || ($name === 'tmpfiles' && $role === RoleName::AppDev)) {
                $artifactDigests[$name] = $digest;
            }
        }
        $publicationByNode = array_column($publications, null, 'node_id');
        $exporters = [];
        $prometheus = null;
        if ($snapshot['metrics_fleet'] !== null) {
            $fleet = ValidatedData::object($snapshot['metrics_fleet']);
            $metrics = Node::query()->findOrFail(ValidatedData::integer($fleet['metrics_node_id']));
            $metricsSsh = app(SwampSshExecutionInput::class)->forNode($metrics);
            $configuration = ValidatedData::stringMap($fleet['runtime_files'])['/etc/orbit/metrics/prometheus.yml'];
            foreach (MetricsFleetCapture::members($fleet) as $member) {
                $target = Node::query()->findOrFail(ValidatedData::integer($member['node_id']));
                $enabled = $member['selected'] === true;
                $service = app(RouteRenderContext::class)->renderRoleCertificates($prospective,
                    fn (): array => app(MetricsFleetCapture::class)->forExporter($metrics, $target, $enabled));
                $publication = $publicationByNode[$target->id]['after'] ?? [];
                if (($publication['caddy'] ?? null) !== null) {
                    $service['caddy'] = null;
                }
                $exporters[] = ['exporter_node_id' => $target->id, 'enabled' => $enabled, 'metrics_address' => $metrics->wireguard_ip,
                    'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($target),
                    'runtime' => ['ssh_execution' => $metricsSsh, 'configuration' => $configuration], 'compose_only' => true,
                    'service' => $service];
            }
            $prometheus = ['node_id' => $metrics->id, 'ssh' => $metricsSsh, 'configuration' => $configuration,
                'candidate' => '/etc/orbit/metrics/prometheus-role-'.$operation->id.'.yml', 'checks' => []];
            foreach ($exporters as $exporter) {
                foreach (ValidatedData::stringList(ValidatedData::object($exporter['service'])['checks']) as $index => $url) {
                    $prometheus['checks'][] = ['node_id' => $exporter['exporter_node_id'], 'ssh' => $metricsSsh, 'url' => $url, 'index' => $index];
                }
            }
        }
        $intent = ['node_id' => $node->id, 'assignment_id' => $assignment->id, 'role' => $role->value,
            'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
            'releases' => ValidatedData::object($snapshot['prerequisites'])['releases'],
            'packages' => array_values(array_diff($packages, ['caddy'])),
            'caddy_package' => $caddy ? NativeCaddyPackageInput::make() : null,
            'directories' => $directories, 'files' => $files, 'php_packages' => $phpPackages, 'php' => $php,
            'managers' => $managers, 'bun' => $bun, 'certificates' => $snapshot['certificates'], 'publications' => $publications, 'runtime' => $runtime['intent'],
            'exporters' => $exporters, 'prometheus' => $prometheus, 'artifact_digests' => $artifactDigests,
            'services' => array_map(static fn (string $name, int $index): array => ['name' => $name, 'index' => $index], $services, array_keys($services)),
            'firewall' => app(NodeRoleFirewallInput::class)->convergeCommands($node, $role)];

        return ['intent' => $intent, 'execution_secrets' => $runtime['execution_secrets'], 'participant_node_ids' => [SwampNode::id(), $node->id]];
    }

    /** @param array<string,mixed> $build
     * @return list<array<string,mixed>>
     */
    private function phpPackages(array $build): array
    {
        return PhpBuildInstaller::packageFiles($build);
    }
}
