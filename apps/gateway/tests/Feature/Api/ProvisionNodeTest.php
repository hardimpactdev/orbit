<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Str;
use Tests\Support\NativeNodeEnrollmentFixtures;

describe('POST /api/v1/nodes', function (): void {
    beforeEach(function (): void {
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
        {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });
        $operator = Node::query()->create([
            'name' => 'operator',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $this->markAsGateway($operator);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    });

    it('returns 422 for a DNS override outside the WireGuard subnet', function (string $address): void {
        $this->postJson('/api/v1/nodes', ['name' => 'peer', 'public_ssh_host' => '192.0.2.3', 'dns_server_override' => $address])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(Node::query()->where('name', 'peer')->exists())->toBeFalse();
    })->with(['10.0.0.2', '2001:db8::53', '10.44.0.0', '10.44.0.255']);

    it('returns a stable validation error when a new Linux node omits its public SSH host', function (): void {
        $converger = new class implements NodeConverger
        {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false, ?Closure $recordObservation = null,
            ): NodeObservation {
                $this->calls++;

                $observation = new NodeObservation('x86_64');
                $recordObservation?->__invoke($observation);

                return $observation;
            }
        };
        app()->instance(NodeConverger::class, $converger);

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'new-linux-node',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.message', 'The request data is invalid.')
            ->assertJsonPath(
                'error.details.public_ssh_host.0',
                'The public SSH host field is required for a new Linux node.',
            );

        expect($converger->calls)
            ->toBe(0)
            ->and(Node::query()->where('name', 'new-linux-node')->exists())
            ->toBeFalse();
    });

    it('requires a unique valid TLD for app-dev nodes', function (): void {
        app()->instance(NodeConverger::class, new class implements NodeConverger
        {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false, ?Closure $recordObservation = null,
            ): NodeObservation {
                $observation = new NodeObservation('x86_64');
                $recordObservation?->__invoke($observation);

                return $observation;
            }
        });
        Node::query()->create([
            'name' => 'existing-dev',
            'public_ssh_host' => '192.0.2.40',
            'tld' => 'test',
        ]);
        $fingerprint = 'SHA256:'.str_repeat(string: 'A', times: 43);

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'missing-tld',
                'public_ssh_host' => '192.0.2.41',
                'architecture' => 'x86_64',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.tld_required');

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'invalid-tld',
                'public_ssh_host' => '192.0.2.42',
                'roles' => ['app-dev'],
                'tld' => 'not valid',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'duplicate-tld',
                'public_ssh_host' => '192.0.2.43',
                'architecture' => 'x86_64',
                'roles' => ['app-dev'],
                'tld' => 'TEST',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.tld_taken');
    });

    it('refuses a Node TLD that covers private names before anything changes', function (?string $vpnDomain, string $tld): void {
        if ($vpnDomain !== null) {
            app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: $vpnDomain);
        }

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'private-tld',
                'public_ssh_host' => '192.0.2.44',
                'architecture' => 'x86_64',
                'roles' => ['app-dev'],
                'tld' => $tld,
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.tld', ['The tld field must be DNS labels that cover no private names.']);

        expect(Node::query()->where('name', 'private-tld')->exists())->toBeFalse();
    })->with([
        'the private space' => [null, 'orbit'],
        'the private space with a leading dot' => [null, '.ORBIT'],
        'the private space under VPN domain corp' => ['corp', 'orbit'],
        'an ancestor of the VPN domain' => ['vpn.corp', 'corp'],
        'an ancestor of a rooted VPN domain' => ['VPN.CORP.', 'CORP'],
        'a multi-label ancestor of a rooted VPN domain' => ['deep.vpn.corp.', 'vpn.corp'],
        'the rooted VPN domain itself' => ['vpn.corp.', 'vpn.corp'],
        'a descendant of the private space' => [null, 'beast.orbit'],
        'a descendant of the rooted VPN domain' => ['VPN.CORP.', 'n1.vpn.corp'],
        'VPN domain corp' => ['corp', 'corp'],
    ]);

    it('rejects a non-member Node TLD owned by an active Cluster before convergence', function (): void {
        $converger = new class implements NodeConverger
        {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false, ?Closure $recordObservation = null,
            ): NodeObservation {
                $this->calls++;

                $observation = new NodeObservation('x86_64');
                $recordObservation?->__invoke($observation);

                return $observation;
            }
        };
        app()->instance(NodeConverger::class, $converger);
        $cluster = Cluster::query()->create([
            'name' => 'development',
            'tld' => 'beast',
            'state' => 'active',
        ]);

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'outside',
                'public_ssh_host' => '192.0.2.44',
                'architecture' => 'x86_64',
                'tld' => 'BEAST',
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.tld_conflict');

        expect(Node::query()->where('name', 'outside')->exists())
            ->toBeFalse()
            ->and($cluster->refresh()->tld)
            ->toBe('beast')
            ->and($converger->calls)
            ->toBe(0);
    });

    it('allows a proposed member Node to share its active Cluster TLD', function (): void {
        $this->beforeApplicationDestroyed(NativeNodeEnrollmentFixtures::bind());
        app()->instance(NodeConverger::class, new class implements NodeConverger
        {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false, ?Closure $recordObservation = null,
            ): NodeObservation {
                $observation = new NodeObservation('x86_64');
                $recordObservation?->__invoke($observation);

                return $observation;
            }
        });
        $cluster = Cluster::query()->create([
            'name' => 'development',
            'tld' => 'beast',
            'state' => 'active',
        ]);

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'member',
                'cluster_id' => $cluster->id,
                'public_ssh_host' => '192.0.2.45',
                'architecture' => 'x86_64',
                'tld' => 'BEAST',
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'node.add');

        $member = Node::query()->where('name', 'member')->sole();

        expect($member->cluster_id)
            ->toBe($cluster->id)
            ->and($member->tld)
            ->toBe('beast');
    });

    it('preserves an existing non-member Node when an active Cluster owns its proposed TLD', function (): void {
        $converger = new class implements NodeConverger
        {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false, ?Closure $recordObservation = null,
            ): NodeObservation {
                $this->calls++;

                $observation = new NodeObservation('x86_64');
                $recordObservation?->__invoke($observation);

                return $observation;
            }
        };
        app()->instance(NodeConverger::class, $converger);
        Cluster::query()->create([
            'name' => 'development',
            'tld' => 'beast',
            'state' => 'active',
        ]);
        $node = Node::query()->create([
            'name' => 'outside',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => 'outside',
            'public_ssh_host' => '192.0.2.46',
            'wireguard_ip' => '10.44.0.46',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'outside',
                'architecture' => 'x86_64',
                'tld' => 'beast',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.tld_conflict');

        expect($node->refresh()->tld)
            ->toBe('outside')
            ->and($node->cluster_id)
            ->toBeNull()
            ->and($node->status)
            ->toBe(LifecycleStatus::Active)
            ->and($converger->calls)
            ->toBe(0);
    });

    it('rejects unsupported platforms and invalid architectures at the API boundary', function (): void {
        $fingerprint = 'SHA256:'.str_repeat(string: 'A', times: 43);

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'windows-node',
                'public_ssh_host' => '192.0.2.44',
                'platform' => 'windows',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'invalid-architecture',
                'public_ssh_host' => '192.0.2.45',
                'architecture' => 'arm64; touch /tmp/orbit',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    });

    it('rejects non-Linux nodes at the API boundary', function (): void {
        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'mac-dev',
                'public_ssh_host' => '192.0.2.46',
                'platform' => 'windows',
                'architecture' => 'arm64',
                'tld' => 'mac.test',
                'roles' => ['app-dev'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(Node::query()->where('name', 'mac-dev')->exists())->toBeFalse();
    });

    it('refuses a Linux ARM architecture in the request before it connects', function (): void {
        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'linux-node',
                'public_ssh_host' => '192.0.2.60',
                'architecture' => 'aarch64',
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.architecture_unsupported');

        expect(Node::query()->where('name', 'linux-node')->exists())->toBeFalse();
    });

    it('rejects an unsafe WireGuard endpoint override at the API boundary', function (): void {
        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'unsafe-endpoint',
                'public_ssh_host' => '192.0.2.46',
                'wireguard_endpoint_override' => "10.0.0.2:51820\nPostUp = touch /tmp/orbit-injected",
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(Node::query()->where('name', 'unsafe-endpoint')->exists())->toBeFalse();
    });

    it('returns the standard validation envelope and records rejection', function (): void {
        $requestId = (string) Str::uuid();

        $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/nodes', [
                'name' => 'not valid',
                'roles' => ['unknown'],
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertUnprocessable()
            ->assertHeader('X-Orbit-Request-Id', $requestId)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details']]);

        $activity = Activity::query()->where('request_id', $requestId)->sole();

        expect($activity->command)
            ->toBe('node:add')
            ->and($activity->status)
            ->toBe('failed')
            ->and($activity->error_code)
            ->toBe('validation.failed');
    });

    it('requires an expected fingerprint before persisting a new node', function (): void {
        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'app-dev',
                'public_ssh_host' => '94.237.40.75',
                'architecture' => 'x86_64',
                'roles' => ['app-dev'],
                'tld' => 'app-dev.test',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.ssh_host_fingerprint_required');

        expect(Node::query()->where('name', 'app-dev')->exists())->toBeFalse();
    });

    it('keeps empty-string normalization for other nullable node fields', function (): void {
        $this->beforeApplicationDestroyed(NativeNodeEnrollmentFixtures::bind());
        app()->instance(NodeConverger::class, new class implements NodeConverger
        {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false, ?Closure $recordObservation = null,
            ): NodeObservation {
                $observation = new NodeObservation('x86_64');
                $recordObservation?->__invoke($observation);

                return $observation;
            }
        });

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'normalized-overrides',
                'public_ssh_host' => '192.0.2.30',
                'architecture' => 'x86_64',
                'wireguard_ip' => '10.44.0.3',
                'wireguard_endpoint_override' => '',
                'dns_server_override' => '',
                'host_key_fingerprint' => 'SHA256:'.str_repeat(string: 'A', times: 43),
            ])
            ->assertAccepted();

        $node = Node::query()->where('name', 'normalized-overrides')->sole();

        expect($node->wireguard_endpoint_override)
            ->toBeNull()
            ->and($node->dns_server_override)
            ->toBeNull();
    });

    it('rejects invalid and assigned WireGuard peer addresses before convergence', function (): void {
        $converger = new class implements NodeConverger
        {
            public int $calls = 0;

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false, ?Closure $recordObservation = null,
            ): NodeObservation {
                $this->calls++;

                $observation = new NodeObservation('x86_64');
                $recordObservation?->__invoke($observation);

                return $observation;
            }
        };
        app()->instance(NodeConverger::class, $converger);
        $fingerprint = 'SHA256:'.str_repeat(string: 'A', times: 43);

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'outside',
                'public_ssh_host' => '192.0.2.20',
                'architecture' => 'x86_64',
                'wireguard_ip' => '10.45.0.2',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'vpn.peer_address_invalid');

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'blank',
                'public_ssh_host' => '192.0.2.24',
                'architecture' => 'x86_64',
                'wireguard_ip' => '',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'vpn.peer_address_invalid');

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'whitespace',
                'public_ssh_host' => '192.0.2.25',
                'architecture' => 'x86_64',
                'wireguard_ip' => '   ',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'vpn.peer_address_invalid');

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'malformed',
                'public_ssh_host' => '192.0.2.22',
                'architecture' => 'x86_64',
                'wireguard_ip' => 'not-an-address',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'vpn.peer_address_invalid');

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'ipv6',
                'public_ssh_host' => '192.0.2.23',
                'architecture' => 'x86_64',
                'wireguard_ip' => 'fd00::2',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'vpn.peer_address_invalid');

        $this
            ->postJson('/api/v1/nodes', [
                'name' => 'duplicate',
                'public_ssh_host' => '192.0.2.21',
                'architecture' => 'x86_64',
                'wireguard_ip' => '10.44.0.2',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'vpn.peer_address_taken');

        expect(
            Node::query()
                ->whereIn(
                    'name',
                    ['outside', 'blank', 'whitespace', 'malformed', 'ipv6', 'duplicate'],
                )
                ->exists(),
        )
            ->toBeFalse()
            ->and($converger->calls)
            ->toBe(0);
    });

    it('rejects invalid user values before convergence', function (): void {
        $calls = 0;
        app()->instance(NodeConverger::class, new class($calls) implements NodeConverger
        {
            public function __construct(
                private int &$calls,
            ) {}

            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false, ?Closure $recordObservation = null,
            ): NodeObservation {
                $this->calls++;

                $observation = new NodeObservation('x86_64');
                $recordObservation?->__invoke($observation);

                return $observation;
            }
        });
        foreach (['', ' ', 'bad/name', "bad\nname", '1name', str_repeat('a', 33)] as $invalid) {
            $this->postJson('/api/v1/nodes', [
                'name' => 'invalid-user-'.md5($invalid),
                'public_ssh_host' => '192.0.2.93',
                'architecture' => 'x86_64',
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
                'user' => $invalid,
            ])->assertUnprocessable();
            $this->postJson('/api/v1/nodes', [
                'name' => 'invalid-orbit-'.md5($invalid),
                'public_ssh_host' => '192.0.2.94',
                'architecture' => 'x86_64',
                'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
                'orbit_user' => $invalid,
            ])->assertUnprocessable();
        }
        expect($calls)->toBe(0);
    });
});
