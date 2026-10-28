<?php

declare(strict_types=1);

use App\Domain\Nodes\CaddyRelease;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

describe('Caddy release floor', function (): void {
    it('reads the release from either build of `caddy version`', function (string $output, ?string $release): void {
        expect(CaddyRelease::reported($output))->toBe($release);
    })->with([
        'Ubuntu archive build' => ["2.6.2\n", '2.6.2'],
        'Caddy project build' => ["v2.11.4 h1:XKxkMTgNSizEvKG6QHue6cAsFOteU2qA61w2tKkCWi0=\n", '2.11.4'],
        'two-part release' => ['v2.8', '2.8.0'],
        'no output' => ['', null],
        'not a version' => ["caddy: command not found\n", null],
    ]);

    it('refuses every release below the one Orbit renders against', function (string $output, bool $supported): void {
        expect(CaddyRelease::supports($output))->toBe($supported);
    })->with([
        'the archive build that cannot read log_skip' => ["2.6.2\n", false],
        'the release that cannot read tls force_automate' => ["2.8.4\n", false],
        'the floor itself' => ["2.9.0\n", true],
        'the current stable' => ["v2.11.4 h1:abc\n", true],
        'unreadable output' => ['nonsense', false],
    ]);

    it('states the floor as a constraint the Tool version helper understands', function (): void {
        expect(CaddyRelease::constraint())->toBe('>=2.9.0')
            ->and(CaddyRelease::MINIMUM)->toBe('2.9.0');
    });
});

describe('Caddy package', function (): void {
    it('installs the package only for the roles that serve through Caddy', function (RoleName $role, bool $needed): void {
        expect((new RolePrerequisites)->needsCaddy(new Node, $role))->toBe($needed);
    })->with([
        'router' => [RoleName::Router, true],
        'app development' => [RoleName::AppDev, true],
        'app production' => [RoleName::AppProd, true],
        'websocket' => [RoleName::WebSocket, true],
        'analytics' => [RoleName::Analytics, true],
        'gateway' => [RoleName::Gateway, true],
        'VPN' => [RoleName::Vpn, false],
        'database' => [RoleName::Database, false],
        'metrics' => [RoleName::Metrics, false],
        'ingress' => [RoleName::Ingress, true],
    ]);

    it('passes only the pinned release, digests, and version floor to the agent', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('caddy.install-package', ['changed' => true, 'version' => '2.10.2']);

        expect((new AgentCaddyPackage($agents))->install(new Node(['name' => 'dev']))->stdout)->toBe(AgentCaddyPackage::Changed."\n")
            ->and($agents->calls[0]['input'])->toBe([
                'release' => '2.11.7',
                'amd64_sha512' => '47e8351c2317b427af14a103e763ca1118a3d2396a88b4c0669cdec9c4a68a957690194e2423a1633f53135741c33a41bdac2b55515b7d0f7adc8b733add50d9',
                'arm64_sha512' => 'ac32f03f0eea04021f2d4e5d89bfd01b84ead115f2eba9d2a0d06447e86d4af932899e56f090993a566d0925dbd1063002929b395541a8c5f5e51d7039f7375a',
                'minimum_version' => '2.9.0',
            ]);
    });

});
