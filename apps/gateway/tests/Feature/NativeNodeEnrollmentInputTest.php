<?php

declare(strict_types=1);

use App\Actions\Nodes\UpdateNodeSettingsAction;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeStorageAppsData;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\NodeEnrollmentInput;
use App\Domain\Nodes\NodeEnrollmentPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    app(VpnSettings::class)->configure('10.44.0.0/24');
    $this->hub = FleetFixtures::node('enrollment-capture-hub', [RoleName::Vpn]);
    $this->node = FleetFixtures::node('enrollment-capture-target');
    $this->node->update(['wireguard_public_key' => base64_encode(str_repeat('n', 32))]);
    $this->node->refresh();
    @mkdir(config('orbit.home').'/wireguard', 0700, true);
    file_put_contents(config('orbit.home').'/wireguard/public.key', base64_encode(str_repeat('h', 32)));
    $this->candidate = new NodeEnrollmentCandidate($this->node->id, $this->node->name, 'managed', 'linux', null,
        '10.44.0.240', '192.0.2.240', 2222, null, 'candidate.test', null, null, null);
});

it('captures immutable target, role tools and prior network without an early identity write', function (): void {
    $prior = $this->node->attributesToArray();
    $input = app(NodeEnrollmentInput::class)->capture($this->node, $this->candidate, 'linux', 'root',
        'SHA256:'.str_repeat('A', 43), 'x86_64', [RoleName::AppDev]);

    expect($this->node->refresh()->attributesToArray())->toBe($prior)
        ->and($input['candidate']['node_id'])->toBe($this->node->id)
        ->and($input['candidate']['user'])->toBe('managed')
        ->and($input['bootstrap']['target']['port'])->toBe(2222)
        ->and($input['bootstrap']['managed_user'])->toBe('managed')
        ->and($input['bootstrap']['command']['public_key'])->toBe($input['ssh_identity']['public_key'])
        ->and($input['ssh_identity']['control_node_id'])->toBe(209)
        ->and($input['hub']['before']['address'])->toBe($this->node->wireguard_ip)
        ->and($input['hub']['desired']['address'])->toBe('10.44.0.240')
        ->and($input['network']['peer']['domains'])->toContain('candidate.test')
        ->and($input['network']['trust']['desired'][0]['shape']['destination'])->toBe('10.44.0.240')
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain('$secret', 'bootstrap-identity-node-', 'worker_token', 'identityContent');
});

it('captures TLD-only work without tool, tunnel or bootstrap effects', function (): void {
    $input = app(NodeEnrollmentInput::class)->capture($this->node, $this->candidate, 'tld_only', 'managed', '', 'x86_64', []);
    expect($input['tools'])->toBeNull()->and($input['network'])->toBeNull()->and($input['hub'])->toBeNull();
});

it('selects the admitted Linux observer pin before the real Node has an observed architecture', function (): void {
    $this->node->update(['architecture' => null, 'status' => 'provisioning', 'wireguard_public_key' => null,
        'ssh_host_key_type' => null, 'ssh_host_key' => null, 'ssh_host_fingerprint' => null]);
    $input = app(NodeEnrollmentInput::class)->capture($this->node, $this->candidate, 'linux', 'root',
        'SHA256:'.str_repeat('A', 43), null, []);
    expect($input['network']['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->candidate))
        ->and($this->node->refresh()->architecture)->toBeNull()
        ->and(app(RouteRenderContext::class)->enrollment())->toBeNull();
});

it('captures admitted settings without resolving the remote account and preserves unrelated members', function (): void {
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $this->node->update(['settings' => ['t3' => ['connection' => 'retained'], 'apps' => ['path' => '/srv/prior']]]);
    $prior = $this->node->settings;
    $input = app(UpdateNodeSettingsAction::class)->captureForEnrollment($this->node, $this->candidate,
        new NodeSettingsData(apps: new NodeStorageAppsData('/srv/candidate')), true);
    expect($input['settings'])->toBe(['apps' => ['path' => '/srv/candidate']])
        ->and($input['storage']['root'])->toBe('/srv/candidate')
        ->and($input['storage']['user'])->toBe('managed')
        ->and($input['storage']['create_missing'])->toBeTrue()
        ->and($this->node->refresh()->settings)->toBe($prior);
});

it('rejects a protected settings root before recording identity or settings', function (): void {
    $prior = $this->node->attributesToArray();
    expect(fn () => app(UpdateNodeSettingsAction::class)->captureForEnrollment($this->node, $this->candidate,
        new NodeSettingsData(apps: new NodeStorageAppsData('/etc/unsafe')), false))
        ->toThrow(ResourceOperationException::class);
    expect($this->node->refresh()->attributesToArray())->toBe($prior);
});

it('admits public settings without remote account discovery', function (): void {
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $prior = $this->node->attributesToArray();
    $input = app(NodeEnrollmentPolicy::class)->prepare($this->node, new ProvisionNodeData(
        name: $this->node->name, publicSshHost: $this->node->public_ssh_host,
        settingsProvided: true, settings: new NodeSettingsData(apps: new NodeStorageAppsData('/srv/candidate')),
    ));
    expect($input['candidate']['wireguard_ip'])->toBe($this->node->wireguard_ip)
        ->and($this->node->refresh()->attributesToArray())->toBe($prior);
});

it('requires a stable bootstrap endpoint when changing a WireGuard address', function (): void {
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $prior = $this->node->attributesToArray();
    foreach ([$this->node->wireguard_ip, $this->candidate->wireguardIp] as $endpoint) {
        try {
            app(NodeEnrollmentPolicy::class)->prepare($this->node, new ProvisionNodeData(
                name: $this->node->name, publicSshHost: $endpoint, wireguardIp: $this->candidate->wireguardIp,
            ));
            test()->fail('A tunnel-dependent recovery endpoint was admitted.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('node.recovery_endpoint_required');
        }
    }
    $input = app(NodeEnrollmentPolicy::class)->prepare($this->node, new ProvisionNodeData(
        name: $this->node->name, publicSshHost: '192.0.2.240', wireguardIp: $this->candidate->wireguardIp,
    ));
    expect($input['candidate']['public_ssh_host'])->toBe('192.0.2.240')
        ->and($this->node->refresh()->attributesToArray())->toBe($prior);
});

it('has no storage duty for a roleless Node without an explicit apps root', function (): void {
    $input = app(UpdateNodeSettingsAction::class)->captureForEnrollment($this->node, $this->candidate, null, false);
    expect($input)->toBe(['settings' => null, 'storage' => null]);
});

it('uses the same prospective firewall rule without changing the actual Node', function (): void {
    $rules = app(RouteRenderContext::class)->renderEnrollment($this->candidate,
        fn (): array => app(NodeRoleFirewallInput::class)->converge($this->node, RoleName::AppProd));
    expect($rules['desired'][0]['shape']['destination'])->toBe('10.44.0.240')
        ->and(app(NodeRoleFirewallInput::class)->trust($this->node)['desired'][0]['shape']['destination'])->toBe($this->node->wireguard_ip)
        ->and($this->node->refresh()->wireguard_ip)->not->toBe('10.44.0.240');
});

it('keeps canonical observer configuration free of target worker installation', function (): void {
    FleetFixtures::node('observer-configuration-gateway', [RoleName::Gateway]);
    $certificates = Mockery::mock(LeafCertificateSigner::class);
    $certificates->shouldReceive('rootCertificate')->andReturn('public-root-certificate');
    app()->instance(LeafCertificateSigner::class, $certificates);
    config(['swamp_workers.nodes' => [$this->node->id]]);
    $configuration = app(NodeAgentSshExecutor::class)->configurationForRoot($this->node, null);
    expect($configuration['configuration'])->toContain('gateway_url = "https://gateway.orbit"')
        ->not->toContain('swamp_worker', 'worker_token', 'enrollment_token');
});
