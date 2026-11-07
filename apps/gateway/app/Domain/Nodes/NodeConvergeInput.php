<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Instances\Environment\InstanceEnvironmentFiles;
use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Routes\NativeRouteLanInput;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\PrivateDnsPublication;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\PhpRuntimeFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteRemovalResidue;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Builder;

/** Captures desired records and templates only. It never probes or changes a Node. */
final readonly class NodeConvergeInput
{
    public const string GroupToken = '__orbit_converge_group__';

    public const string HomeToken = '/__orbit_converge_home__';

    /** @param iterable<NodeFootprintArtifact> $artifacts */
    public function __construct(private NodeFootprint $footprint, private AgentDelivery $delivery,
        #[Tag(NodeFootprintArtifact::class)] private iterable $artifacts) {}

    /** @return array<string,mixed> */
    public function captureNative(Node $node, bool $force, bool $firstVisit): array
    {
        $snapshot = $this->capture($node, $force, $firstVisit);
        unset($snapshot['secret_hash']);
        $payloads = ValidatedData::object($snapshot['payloads']);
        if (isset($payloads['route-residue'])) {
            $residue = ValidatedData::object($payloads['route-residue']);
            $lan = app(NativeRouteLanInput::class);
            $residue['lan'] = ['node_id' => $node->id, 'rules' => $lan->changes([], $lan->forSites($node, app(DevelopmentSiteRepository::class)->all()))];
            $residue['ingress_commands'] = $residue['ingress'] === null ? null : app(NodeRoleFirewallInput::class)->convergeCommands($node, RoleName::Ingress);
            unset($residue['ingress'], $residue['firewall']);
            $payloads['route-residue'] = $residue;
        }

        $native = [...$snapshot, 'payloads' => $payloads, 'user' => $node->user,
            'ssh' => app(SwampSshExecutionInput::class)->forNode($node), 'agent' => $this->agentFiles($node, $payloads),
            // A VPN Node republishes the complete private DNS on every converge: a fresh render, taken now, not the digest of an artifact.
            'roles' => $this->roles($node, $payloads, PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists() ? app(PrivateDnsPublication::class)->entry($node) : null),
            'verifier_hash' => $node->agent_secret_hash];

        return $native['agent'] === null || ! is_string($native['verifier_hash']) ? $native : [...$native, 'footprint_check' => self::footprintCheck($native)];
    }

    /**
     * The Node agent's files as official cfgmgmt writes them: the unit already opens the apps root of the account
     * Orbit creates (`/home/<user>`), and the Instance checkouts under that root whose `.env` loses its world bits.
     *
     * @param  array<string,mixed>  $payloads
     *                                         The production homes on the Node, on which the `orbit-env` group gets traverse so the agent reaches each `.env`.
     * @return array{url:?string,files:list<array{path:string,content:string}>,unit:string,checkouts:list<string>,homes:list<string>}|null
     */
    private function agentFiles(Node $node, array $payloads): ?array
    {
        if (! isset($payloads['agent'])) {
            return null;
        }
        $agent = ValidatedData::object($payloads['agent']);
        $root = is_string($agent['apps_root'] ?? null) ? $agent['apps_root'] : ($node->user === 'root' ? '/root' : '/home/'.$node->user).'/apps';
        $configuration = app(NodeAgentSshExecutor::class)->configurationForRoot($node, $root);
        $checkouts = array_values(array_filter(is_array($agent['checkouts'] ?? null) ? $agent['checkouts'] : [],
            static fn (mixed $path): bool => is_string($path) && str_starts_with($path, $root.'/')));

        return ['url' => is_string($agent['url'] ?? null) ? $agent['url'] : null,
            'files' => [['path' => NodeAgentFootprint::ConfigurationPath, 'content' => $configuration['configuration']],
                ['path' => NodeAgentFootprint::CertificatePath, 'content' => $configuration['certificate']]],
            'unit' => $configuration['unit'], 'checkouts' => $checkouts, 'homes' => app(InstanceEnvironmentFiles::class)->homes($node)];
    }

    /**
     * The role parts in the shape of node.converge's part steps. A part the snapshot does not apply is null; a
     * Caddyfile the Gateway could not render is null too, and the projection reports it as skipped.
     *
     * @param  array<string,mixed>  $payloads
     * @param  array<string,mixed>|null  $dns  The private DNS publication entry of a VPN Node.
     * @return array<string,mixed>
     */
    public function roles(Node $node, array $payloads, ?array $dns = null): array
    {
        $home = $node->user === 'root' ? '/root' : '/home/'.$node->user;
        $caddy = isset($payloads['caddy']) ? ValidatedData::object($payloads['caddy']) : null;
        $php = [];
        $configurations = isset($payloads['php-runtime']) ? ValidatedData::object($payloads['php-runtime'])['configurations'] : [];
        foreach (is_array($configurations) ? $configurations : [] as $configuration) {
            $configuration = ValidatedData::object($configuration);
            $php[ValidatedData::string($configuration['version'])] = ValidatedData::string($configuration['runtime_ini']);
        }

        return ['user' => $node->user, 'home' => $home,
            'caddy_package' => isset($payloads['caddy-package']) ? NativeCaddyPackageInput::make() : null,
            'caddy' => $caddy === null || isset($caddy['error_code']) ? null : $caddy,
            'dns' => $dns,
            'php' => (object) $php, 'php_versions' => array_keys($php),
            'tmpfiles' => isset($payloads['tmpfiles']) ? ValidatedData::string(ValidatedData::object($payloads['tmpfiles'])['rule']) : null,
            'residue' => isset($payloads['route-residue']) ? $this->residueParts($node, ValidatedData::object($payloads['route-residue']), new ManagedUserAccount($node->user, $node->user, $home)) : null];
    }

    /**
     * The hashes `Check the footprint` holds the Node to, as `sha256sum --check` reads them: the pinned binary, the
     * recorded key and each agent file.
     *
     * @param  array<string,mixed>  $snapshot
     */
    public static function footprintCheck(array $snapshot): string
    {
        $agent = ValidatedData::object($snapshot['agent']);
        $lines = [ValidatedData::string($snapshot['agent_sha256']).'  /usr/local/bin/orbit-agent',
            ValidatedData::string($snapshot['verifier_hash']).'  /etc/orbit/agent/secret'];
        foreach (is_array($agent['files'] ?? null) ? $agent['files'] : [] as $file) {
            $file = ValidatedData::object($file);
            $lines[] = hash('sha256', ValidatedData::string($file['content'])).'  '.ValidatedData::string($file['path']);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Route residue as the residue steps take it: PHP pool files rendered for the Node's own account, one per PHP
     * version, empty for a version that no longer has a pool.
     *
     * @param  array<string,mixed>  $residue
     * @return array<string,mixed>|null
     */
    private function residueParts(Node $node, array $residue, ManagedUserAccount $account): ?array
    {
        $rows = is_array($residue['rows']) ? $residue['rows'] : [];
        if ($rows === []) {
            return null;
        }
        $steps = array_merge(...array_map(static fn (mixed $row): array => ValidatedData::stringList(ValidatedData::object($row)['steps']), $rows));
        $caddy = in_array('caddy', $steps, true) && is_array($residue['caddy']) && ! isset($residue['caddy']['error_code']) ? $residue['caddy'] : null;
        $pools = [];
        if (in_array('php', $steps, true)) {
            $rendered = [];
            foreach (app(DevelopmentSiteRepository::class)->forNode($node)->filter(static fn (DevelopmentSite $site): bool => $site->phpVersion !== null && ! $site->isProxy() && ! $site->usesDedicatedPhpRuntime())->groupBy('phpVersion') as $version => $members) {
                $rendered[(string) $version] = app(DevelopmentPhpFpmConfigRenderer::class)->render($members->values(), $account);
            }
            $known = array_map(static fn (array $input): string => ValidatedData::string($input['version']), app(PhpRuntimeFootprintArtifact::class)->inputs($node));
            $versions = array_unique([...$known, ...array_map(strval(...), array_keys($rendered))]);
            sort($versions, SORT_STRING);
            foreach ($versions as $version) {
                $pools[] = ['version' => $version, 'path' => '/etc/php/'.$version.'/fpm/pool.d/orbit-scopes.conf', 'content' => $rendered[$version] ?? ''];
            }
        }

        return ['caddy' => $caddy, 'pools' => $pools, 'certificates' => $residue['certificates'], 'lan' => $residue['lan'] ?? null,
            'ingress_commands' => $residue['ingress_commands'] ?? null];
    }

    /** @return array<string,mixed> */
    public function capture(Node $node, bool $force, bool $firstVisit): array
    {
        $expected = $this->footprint->expected($node)->artifacts;
        $record = $this->footprint->recorded($node);
        $recorded = $record === null ? [] : ($record->artifacts ?? []);
        $plan = [];
        $payloads = [];
        $refusal = null;
        $network = null;
        foreach ($this->artifacts as $artifact) {
            $name = $artifact->name();
            if (! array_key_exists($name, $expected)) {
                continue;
            }
            $digest = $expected[$name];
            $repair = ($recorded[$name] ?? null) === $digest;
            $required = $force || ! $repair || in_array($name, ['agent', 'caddy', 'network'], true);
            $plan[] = compact('name', 'digest', 'required', 'repair');
            if (str_starts_with($digest, 'unavailable:')) {
                $refusal = 'node.footprint_failed';

                continue;
            }
            if (! in_array($name, ['agent', 'ai-router', 'caddy-package', 'caddy', 'network', 'route-residue', 'php-runtime', 'tmpfiles'], true)) {
                $refusal = 'operation.kind_unavailable';

                continue;
            }
            if (! $required) {
                continue;
            }
            // The ai-router part only versions the collector Node by its agent pin; the extension's own converge runs the
            // collector, so the part needs no step and is applied with the agent.
            if ($name === 'ai-router') {
                continue;
            }
            if ($name === 'network') {
                $network = [...app(NodeNetworkConvergeInput::class)->converge($node), 'required' => $force || ! $repair, 'handshake_after' => now()->getTimestamp() - 180];

                continue;
            }
            $payloads[$name] = match ($name) {
                'agent' => [...app(NodeAgentSshExecutor::class)->configurationForRoot($node, null),
                    'user' => $node->user, 'apps_root' => ValidatedData::object($node->settings['apps'] ?? [])['path'] ?? null,
                    'checkouts' => $node->instances()->with('project')->orderBy('id')->get()->map(static fn ($instance): string => $instance->applicationDirectory())->all(),
                    'url' => $this->delivery->artifacts()[AgentDelivery::key($node)]['url'] ?? null],
                'caddy-package' => NativeCaddyPackageInput::make(),
                'caddy' => $this->caddy($node),
                'route-residue' => $this->residue($node),
                'php-runtime' => ['configurations' => app(PhpRuntimeFootprintArtifact::class)->inputs($node)],
                'tmpfiles' => ['rule' => TmpfilesFootprintArtifact::Rule],
            };
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => $this->delivery->pin($node),
            'force_artifacts' => $force, 'first_visit' => $firstVisit, 'artifacts' => $plan,
            'payloads' => $payloads, 'network' => $network, 'network_mode' => $network['mode'] ?? 'peer', 'refusal' => $refusal,
            'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id]];
    }

    /** @return array<string,mixed> */
    private function caddy(Node $node): array
    {
        $render = app(NodeCaddyfileRenderer::class)->render($node);

        return $render->buildable() ? CaddyPublicationPlan::input($render, $node->id) : ['error_code' => 'caddy.render_failed', 'stage' => 'render'];
    }

    /** @return Builder<RouteRemovalResidue> */
    public static function residueRows(Node $node): Builder
    {
        return RouteRemovalResidue::query()->where('node_id', $node->id)
            ->whereNotIn('route_id', Route::query()->select('id'))->orderBy('id');
    }

    /** @return array<string,mixed> */
    public function residue(Node $node): array
    {
        $rows = self::residueRows($node)->get();
        $sites = app(DevelopmentSiteRepository::class)->forNode($node);
        $configurations = [];
        if ($rows->contains(static fn (RouteRemovalResidue $row): bool => in_array('php', $row->steps, true))) {
            foreach ($sites->filter(static fn (DevelopmentSite $site): bool => $site->phpVersion !== null && ! $site->isProxy() && ! $site->usesDedicatedPhpRuntime())->groupBy('phpVersion') as $version => $members) {
                $configurations[$version] = app(DevelopmentPhpFpmConfigRenderer::class)->render($members->values(),
                    new ManagedUserAccount($node->user, self::GroupToken, self::HomeToken));
            }
        }
        $certificates = [];
        $firewall = [];
        foreach ($rows as $row) {
            if (in_array('certificates', $row->steps, true)) {
                foreach (["route-{$row->route_id}", "route-{$row->route_id}-router", "route-{$row->route_id}-router-hostname-change", "route-{$row->route_id}-ingress"] as $scope) {
                    if (! $sites->contains(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
                        $certificates[] = $scope;
                    }
                }
            }
            if (in_array('firewall', $row->steps, true) && ! $sites->contains(static fn (DevelopmentSite $site): bool => $site->domain === $row->domain)) {
                $firewall[] = ['operation' => 'remove', 'route_id' => $row->route_id];
            }
        }

        return ['rows' => $rows->map(static fn (RouteRemovalResidue $row): array => $row->only(['id', 'route_id', 'domain', 'steps', 'attempts']))->all(),
            'caddy' => $this->caddy($node), 'user' => $node->user,
            'php' => $rows->contains(static fn (RouteRemovalResidue $row): bool => in_array('php', $row->steps, true)) ? (object) $configurations : null,
            'certificates' => $certificates, 'firewall' => $firewall,
            'ingress' => $firewall !== [] && $node->roles()->where('role', RoleName::Ingress)->exists()
                ? app(NodeRoleFirewallInput::class)->converge($node, RoleName::Ingress) : null];
    }
}
