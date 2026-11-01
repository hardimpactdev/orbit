<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\NodeRoleConvergeInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Fleet\Footprint\AgentFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Setting;
use App\Support\ValidatedData;
use Tests\Support\Fleet\FleetFixtures;

/** @param array<string,mixed> $changes */
function roleConvergeCandidate(Node $node, array $changes = []): NodeEnrollmentCandidate
{
    return new NodeEnrollmentCandidate(...array_replace([
        'nodeId' => $node->id, 'name' => 'candidate-role', 'user' => 'candidate-user', 'platform' => 'linux',
        'architecture' => null, 'wireguardIp' => '10.44.0.201', 'publicSshHost' => '192.0.2.201',
        'publicSshPort' => 2222, 'clusterId' => null, 'tld' => 'candidate.internal', 'lanIp' => null,
        'wireguardEndpointOverride' => null, 'dnsServerOverride' => '10.44.0.200',
    ], $changes));
}

it('captures a real provisional role with candidate identity settings and required pin binding without changing records', function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    FleetFixtures::bind([app(AgentFootprintArtifact::class)]);
    FleetFixtures::node('role-capture-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('reserved-role', [RoleName::AppDev], managed: false, status: LifecycleStatus::Provisioning);
    $node->update(['architecture' => null, 'settings' => ['apps' => ['path' => '/srv/old', 'extra' => 'retained'], 't3' => ['id' => 'private-member']]]);
    $node->roles->sole()->update(['status' => LifecycleStatus::Provisioning]);
    $before = $node->fresh()->getAttributes();
    $roles = $node->roles()->get()->toArray();
    $settings = Setting::query()->get()->toArray();
    $capture = app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole(), roleConvergeCandidate($node), ['apps' => ['path' => '/srv/candidate']]);
    $intent = $capture['intent'];

    expect($intent['user'])->toBe('candidate-user')
        ->and($intent['storage_root'])->toBe('/srv/candidate')
        ->and($intent['footprint']['payloads']['agent']['user'])->toBe('candidate-user')
        ->and($intent['footprint']['payloads']['agent']['apps_root'])->toBe('/srv/candidate')
        ->and($intent['prepared_tools_required'])->toBeTrue()
        ->and($intent['javascript'])->toBeNull()
        ->and($intent['managers'])->toBe([])
        ->and($intent['ssh']['target'])->toBe(['name' => 'target', 'host' => '10.44.0.201', 'user' => 'candidate-user', 'port' => 22, 'host_keys' => null])
        ->and($intent['targets']->{$node->id})->toBe($intent['ssh'])
        ->and(json_encode($intent))->not->toContain('private-member')
        ->and($node->fresh()->getAttributes())->toBe($before)
        ->and($node->roles()->get()->toArray())->toBe($roles)
        ->and(Setting::query()->get()->toArray())->toBe($settings)
        ->and(Operation::query()->count())->toBe(0)
        ->and(app(RouteRenderContext::class)->enrollment())->toBeNull();
});

it('distinguishes omitted managed role settings from an explicit clear and restores the render scope after refusal', function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    FleetFixtures::bind([app(AgentFootprintArtifact::class)]);
    FleetFixtures::node('settings-role-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('settings-role', [RoleName::AppDev]);
    $node->update(['settings' => ['apps' => ['path' => '/srv/stored'], 't3' => ['id' => 'kept']]]);
    $input = app(NodeRoleConvergeInput::class);
    $candidate = roleConvergeCandidate($node);
    $omitted = $input->capture($node, $node->roles->sole(), $candidate)['intent'];
    $cleared = $input->capture($node, $node->roles->sole(), $candidate, null)['intent'];
    expect($omitted['storage_root'])->toBe('/srv/stored')
        ->and($cleared['storage_root'])->toBeNull()
        ->and($cleared['footprint']['payloads']['agent']['apps_root'])->toBeNull();
    $other = FleetFixtures::node('foreign-role', [RoleName::Database]);
    expect(fn () => $input->capture($node, $other->roles->sole(), $candidate, ['apps' => ['path' => '/srv/rejected']]))
        ->toThrow(ResourceOperationException::class)
        ->and(app(RouteRenderContext::class)->enrollment())->toBeNull()
        ->and(app(RouteRenderContext::class)->settings($node))->toBe($node->settings);
    app(RouteRenderContext::class)->renderEnrollment($candidate, function () use ($input, $node, $candidate): void {
        $actual = $input->capture($node, $node->roles->sole())['intent'];
        expect($actual['user'])->toBe($node->user)
            ->and($actual['storage_root'])->toBe('/srv/stored')
            ->and(app(RouteRenderContext::class)->enrollment())->toBe($candidate);
    });
    expect(fn () => $input->capture($node, $node->roles->sole(), roleConvergeCandidate($other)))
        ->toThrow(ResourceOperationException::class);
});

it('captures the desired Metrics owner address and provisional member without granting ordinary exporter eligibility', function (): void {
    FleetFixtures::bind();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    FleetFixtures::node('candidate-metrics-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('reserved-metrics', [RoleName::Metrics], managed: false, status: LifecycleStatus::Provisioning);
    $node->update(['architecture' => null]);
    $node->roles->sole()->update(['status' => LifecycleStatus::Provisioning]);
    $capture = app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole(), roleConvergeCandidate($node));
    $intent = $capture['intent'];
    expect($intent['runtime']['address'])->toBe('10.44.0.201')
        ->and($intent['metrics_fleet']['metrics_address'])->toBe('10.44.0.201')
        ->and(collect($intent['metrics_fleet']['fleet'])->firstWhere('node_id', $node->id))
        ->toMatchArray(['address' => '10.44.0.201', 'name' => 'candidate-role', 'selected' => true])
        ->and($intent['runtime']['public_configuration']['/etc/orbit/metrics/prometheus.yml'])->toContain('10.44.0.201:9100')
        ->and(app(ManagedNodeEligibility::class)->allows($node))->toBeFalse()
        ->and(app(MetricsExporterProjection::class)->forNode($node, $node))->toBeNull()
        ->and(Operation::query()->count())->toBe(0);
});

it('captures complete standalone role intent through the canonical 209 descriptor without host effects', function (RoleName $role): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('native-role-input', [$role]);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $before = $node->refresh()->getAttributes();
    $settings = Setting::query()->get()->toArray();

    $capture = app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole());
    $intent = $capture['intent'];
    $ssh = ValidatedData::object($intent['ssh']);
    $target = ValidatedData::object($ssh['target']);

    expect($intent['node_id'])->toBe($node->id)
        ->and($intent['role'])->toBe($role->value)
        ->and($ssh['control_node_id'])->toBe(209)
        ->and($target['host'])->toBe($node->wireguard_ip)
        ->and($ssh['identity_file'])->toBe('/run/credentials/orbit-node-worker.service/proxy-ssh-identity')
        ->and($capture['participant_node_ids'])->toContain($node->id, 209)
        ->and($intent['footprint'])->not->toHaveKey('secret_hash')
        ->and($intent)->not->toHaveKeys(['proxy_node_ids', 'publication'])
        ->and($intent['publications'])->toBeArray()
        ->and($intent['created'])->toBeFalse()
        ->and($node->refresh()->getAttributes())->toBe($before)
        ->and(Setting::query()->get()->toArray())->toBe($settings)
        ->and(Operation::query()->count())->toBe(0);
    $target = ((array) $intent['targets'])[(string) $node->id];
    expect($target)->toBe($intent['ssh']);
    if ($role === RoleName::Database) {
        expect($intent['publications'])->toBe([])->and($intent['caddy_package'])->toBeNull();
    }
})->with([RoleName::Database, RoleName::CiRunner, RoleName::AppDev, RoleName::AppProd]);

it('refuses a foreign role assignment before capturing any standalone intent', function (): void {
    FleetFixtures::bind();
    $owner = FleetFixtures::node('role-owner', [RoleName::Database]);
    $other = FleetFixtures::node('role-other');

    expect(fn () => app(NodeRoleConvergeInput::class)->capture($other, $owner->roles->sole()))
        ->toThrow(ResourceOperationException::class);
});

it('requires the existing target host pin instead of selecting another connection', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('role-unpinned', [RoleName::Database]);
    $node->update(['ssh_host_key' => null]);

    expect(fn () => app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole()))
        ->toThrow(ResourceOperationException::class);
});

it('captures the final prospective role footprint without storing certificate readiness', function (): void {
    FleetFixtures::bind([app(CaddyFootprintArtifact::class)]);
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return 'public-role-convergence-root';
        }
    });
    FleetFixtures::node('role-gateway', [RoleName::Gateway, RoleName::Vpn]);
    $node = FleetFixtures::node('role-websocket', [RoleName::WebSocket]);
    $settings = Setting::query()->get()->toArray();

    $capture = app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole());
    $intent = $capture['intent'];
    $publications = $intent['publications'];
    if (! is_array($publications)) {
        throw new RuntimeException('Missing publication list');
    }
    $found = false;
    foreach ($publications as $raw) {
        $row = ValidatedData::object($raw);
        if ($row['node_id'] !== $node->id) {
            continue;
        }
        $found = true;
        $before = ValidatedData::object(ValidatedData::object($row['before'])['caddy']);
        $after = ValidatedData::object(ValidatedData::object($row['after'])['caddy']);
        expect($before['content'])->not->toContain('# orbit: websocket reverb.orbit')
            ->and($after['content'])->toContain('# orbit: websocket reverb.orbit');
    }
    $footprint = ValidatedData::object($intent['footprint']);
    $payloads = ValidatedData::object($footprint['payloads']);
    $caddy = ValidatedData::object($payloads['caddy']);
    expect($found)->toBeTrue()
        ->and($caddy['content'])->toContain('# orbit: websocket reverb.orbit')
        ->and(app(CaddySiteCertificates::class)->published($node->id, 'websocket'))->toBeFalse()
        ->and(Setting::query()->get()->toArray())->toBe($settings);
});

it('captures public Metrics specifications without rendering the existing password into intent', function (): void {
    FleetFixtures::bind();
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return 'public-metrics-role-root';
        }
    });
    FleetFixtures::node('metrics-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('metrics-role-owner', [RoleName::Metrics]);
    $before = Setting::query()->get()->toArray();

    $capture = app(NodeRoleConvergeInput::class)->capture($node, $node->roles->sole());
    $runtime = ValidatedData::object($capture['intent']['runtime']);
    $configuration = ValidatedData::object($runtime['public_configuration']);

    expect($configuration)->toHaveKey('/etc/orbit/metrics/prometheus.yml')
        ->and($configuration)->not->toHaveKey('/etc/orbit/metrics/grafana/admin-password')
        ->and($capture['participant_node_ids'])->toContain(209, $node->id)
        ->and(Setting::query()->get()->toArray())->toBe($before);
});

it('prepares and captures the real provisional Analytics Process on the admitted address and resolves its existing material', function (): void {
    FleetFixtures::bind();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    FleetFixtures::node('analytics-admission-gateway', [RoleName::Gateway]);
    $storage = analytics_storage_processes();
    $storage['node']->update(['architecture' => 'x86_64', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAteststorage']);
    $node = FleetFixtures::node('reserved-analytics', [RoleName::Analytics], managed: false, status: LifecycleStatus::Provisioning);
    $node->update(['architecture' => null, 'wireguard_ip' => null]);
    $assignment = $node->roles->sole();
    $assignment->update(['status' => LifecycleStatus::Provisioning]);
    app(AnalyticsRoleSettingsRepository::class)->store($node,
        new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id));
    $candidate = roleConvergeCandidate($node);
    app(NodeRoleRuntimeInput::class)->prepare($node, $assignment, $candidate);
    $before = $node->fresh()->getAttributes();
    $processes = Process::query()->get()->toArray();
    $settings = Setting::query()->get()->toArray();
    $capture = app(NodeRoleConvergeInput::class)->capture($node, $assignment, $candidate);
    $material = app(NodeRoleRuntimeSource::class)->resolveRoleMaterial($capture['intent'], $node->id, $candidate);
    expect($capture['intent']['runtime']['health_url'])->toBe('http://10.44.0.201:8000/api/health')
        ->and($material->value['installation']['definition']['ports'])->toBe(['10.44.0.201:8000:8000/tcp'])
        ->and($material->value['installation']['spec'])->toBe($capture['intent']['runtime']['installation']['spec'])
        ->and($node->fresh()->getAttributes())->toBe($before)
        ->and(Process::query()->get()->toArray())->toBe($processes)
        ->and(Setting::query()->get()->toArray())->toBe($settings)
        ->and(Operation::query()->count())->toBe(0);
    expect(fn () => app(ProcessTargetResolver::class)->forNodeAdmission($node))
        ->toThrow(ResourceOperationException::class);
});

it('captures candidate Router LAN membership and keeps explicit policy overrides authoritative', function (): void {
    FleetFixtures::bind();
    $old = Cluster::query()->create(['name' => 'old-role-lan', 'state' => 'active']);
    $next = Cluster::query()->create(['name' => 'new-role-lan', 'state' => 'active']);
    $node = FleetFixtures::node('reserved-router', managed: false, status: LifecycleStatus::Provisioning);
    $node->update(['cluster_id' => $old->id, 'lan_ip' => '192.0.2.10', 'architecture' => null, 'wireguard_ip' => null]);
    $assignment = $node->roles()->create(['role' => RoleName::Router, 'cluster_id' => $old->id, 'status' => LifecycleStatus::Provisioning]);
    $node->unsetRelation('roles');
    $source = FleetFixtures::node('new-lan-member', [RoleName::Database]);
    $source->update(['cluster_id' => $next->id, 'lan_ip' => '192.0.2.20', 'wireguard_public_key' => 'public-member']);
    $before = $node->fresh()->getAttributes();
    $candidate = roleConvergeCandidate($node, ['clusterId' => $next->id, 'lanIp' => '192.0.2.30']);
    $intent = app(NodeRoleConvergeInput::class)->capture($node, $assignment, $candidate)['intent'];
    expect($intent['router_lan']['desired'])->toHaveCount(3);
    foreach ($intent['router_lan']['desired'] as $rule) {
        expect($rule)->toMatchArray(['source' => '192.0.2.20', 'destination' => '192.0.2.30']);
    }
    $catalog = app(NodeFirewallRuleCatalog::class);
    $actual = $catalog->routerLanIngress($node);
    expect($actual)->toBe([])
        ->and($node->fresh()->getAttributes())->toBe($before);
    $overridden = app(RouteRenderContext::class)->renderEnrollment($candidate,
        fn () => $catalog->routerLanIngress($node, [$node->id => ['cluster_id' => $old->id, 'lan_ip' => '192.0.2.40']]));
    expect($overridden)->toBe([]);
});
