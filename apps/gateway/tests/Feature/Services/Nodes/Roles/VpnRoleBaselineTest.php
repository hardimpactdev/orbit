<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Dns\OrbitDnsServiceInstaller;
use App\Services\Nodes\Roles\RoleBaselines\VpnRoleBaseline;
use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('VpnRoleBaseline', function (): void {
    beforeEach(function (): void {
        config()->set('services.wg_easy.password', 'secret-password');
        config()->set('services.wg_easy.username', 'orbit-admin');

        $this->wgEasyInstaller = new class extends WgEasyServiceInstaller
        {
            /** @var list<array{publicHost: string, username: string, password: string, wireguardCidr: string, wireguardPort: int, dnsIp: string}> */
            public array $invocations = [];

            public function __construct() {}

            public function install(
                string $publicHost,
                string $username,
                string $password,
                string $wireguardCidr = '10.6.0.0/24',
                int $wireguardPort = 51820,
                string $dnsIp = '10.6.0.1',
            ): void {
                $this->invocations[] = [
                    'publicHost' => $publicHost,
                    'username' => $username,
                    'password' => $password,
                    'wireguardCidr' => $wireguardCidr,
                    'wireguardPort' => $wireguardPort,
                    'dnsIp' => $dnsIp,
                ];
            }
        };

        $this->dnsInstaller = new class extends OrbitDnsServiceInstaller
        {
            public int $installs = 0;

            public function __construct() {}

            public function install(): void
            {
                $this->installs++;
            }
        };
    });

    it('installs wg-easy and orbit-dns when the vpn role has a public endpoint', function (): void {
        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'settings' => [
                'public_endpoint' => 'vpn.example.com',
                'wireguard_cidr' => '10.7.0.0/24',
                'wireguard_port' => 51830,
                'dns_ip' => '10.7.0.1',
            ],
        ]);

        $baseline = new VpnRoleBaseline($this->wgEasyInstaller, $this->dnsInstaller);

        $baseline->converge($node, $assignment);

        expect($this->wgEasyInstaller->invocations)->toBe([
            [
                'publicHost' => 'vpn.example.com',
                'username' => 'orbit-admin',
                'password' => 'secret-password',
                'wireguardCidr' => '10.7.0.0/24',
                'wireguardPort' => 51830,
                'dnsIp' => '10.7.0.1',
            ],
        ])->and($this->dnsInstaller->installs)->toBe(1);
    });

    it('uses the default wg-easy username when it is not configured', function (): void {
        config()->set('services.wg_easy.username', null);

        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'settings' => [
                'public_endpoint' => 'vpn.example.com',
            ],
        ]);

        $baseline = new VpnRoleBaseline($this->wgEasyInstaller, $this->dnsInstaller);

        $baseline->converge($node, $assignment);

        expect($this->wgEasyInstaller->invocations[0]['username'])->toBe('orbit')
            ->and($this->wgEasyInstaller->invocations[0]['wireguardCidr'])->toBe('10.6.0.0/24')
            ->and($this->wgEasyInstaller->invocations[0]['wireguardPort'])->toBe(51820)
            ->and($this->wgEasyInstaller->invocations[0]['dnsIp'])->toBe('10.6.0.1');
    });

    it('does nothing when the vpn role has no public endpoint', function (): void {
        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'settings' => [
                'public_endpoint' => null,
            ],
        ]);

        $baseline = new VpnRoleBaseline($this->wgEasyInstaller, $this->dnsInstaller);

        $baseline->converge($node, $assignment);

        expect($this->wgEasyInstaller->invocations)->toBe([])
            ->and($this->dnsInstaller->installs)->toBe(0);
    });

    it('requires the wg-easy password when converging runtime', function (): void {
        config()->set('services.wg_easy.password', null);

        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'settings' => [
                'public_endpoint' => 'vpn.example.com',
            ],
        ]);

        $baseline = new VpnRoleBaseline($this->wgEasyInstaller, $this->dnsInstaller);

        expect(fn (): mixed => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'WG_EASY_PASSWORD is required to converge the vpn role runtime.');
    });

    it('cannot be removed independently', function (): void {
        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
        ]);

        $baseline = new VpnRoleBaseline($this->wgEasyInstaller, $this->dnsInstaller);

        expect(fn (): mixed => $baseline->remove($node, $assignment, purgeData: false))
            ->toThrow(RuntimeException::class, 'The vpn role cannot be removed independently in this version.');
    });
});
