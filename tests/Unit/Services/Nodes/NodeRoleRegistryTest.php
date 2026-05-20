<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\AgentRoleSettings;
use App\Data\Nodes\RoleSettings\AppDevelopmentRoleSettings;
use App\Data\Nodes\RoleSettings\AppProductionRoleSettings;
use App\Data\Nodes\RoleSettings\DatabaseRoleSettings;
use App\Data\Nodes\RoleSettings\EmptyRoleSettings;
use App\Data\Nodes\RoleSettings\VpnRoleSettings;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Services\Nodes\Roles\NodeRoleRegistry;

describe('node role registry', function (): void {
    it('defines the initial role compatibility matrix', function (): void {
        $registry = new NodeRoleRegistry;

        expect($registry->definition('gateway')->conflictsWith)->toBe([
            'app-development',
            'app-production',
            'database',
            'agent',
        ]);

        expect($registry->definition('vpn')->conflictsWith)->toBe([
            'app-development',
            'app-production',
            'database',
            'agent',
        ]);

        expect($registry->definition('app-development')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'app-production',
            'agent',
        ]);

        expect($registry->definition('app-production')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'app-development',
            'agent',
        ]);

        expect($registry->definition('database')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'agent',
        ]);

        expect($registry->definition('agent')->conflictsWith)->toBe([
            'gateway',
            'vpn',
            'app-development',
            'app-production',
            'database',
        ]);
    });

    it('defines supported platforms and assignability for the initial roles', function (): void {
        $registry = new NodeRoleRegistry;

        expect($registry->definition('gateway')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('gateway')->assignableByRoleCommand)->toBeFalse()
            ->and($registry->definition('gateway')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('app-development')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('app-development')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('app-development')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('app-production')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('app-production')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('app-production')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('database')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('database')->assignableByRoleCommand)->toBeTrue()
            ->and($registry->definition('database')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('agent')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('agent')->assignableByRoleCommand)->toBeFalse()
            ->and($registry->definition('agent')->assignableByNodeNew)->toBeTrue()
            ->and($registry->definition('vpn')->supportedPlatforms)->toBe(['ubuntu'])
            ->and($registry->definition('vpn')->assignableByRoleCommand)->toBeFalse()
            ->and($registry->definition('vpn')->assignableByNodeNew)->toBeFalse();
    });

    it('hydrates role-specific settings dtos', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('app-development')
            ->settingsFromArray(['tld' => 'test']);

        expect($settings)
            ->toBeInstanceOf(AppDevelopmentRoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['tld' => 'test']);
    });

    it('hydrates agent settings dtos with default tld', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray([]);

        expect($settings)
            ->toBeInstanceOf(AgentRoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['tld' => 'agent']);
    });

    it('hydrates agent settings dtos with explicit tld', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray(['tld' => 'custom']);

        expect($settings)
            ->toBeInstanceOf(AgentRoleSettings::class)
            ->and($settings->toArray())
            ->toBe(['tld' => 'custom']);
    });

    it('hydrates vpn settings with defaults', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('vpn')
            ->settingsFromArray([]);

        expect($settings)
            ->toBeInstanceOf(VpnRoleSettings::class)
            ->and($settings->toArray())
            ->toBe([
                'public_endpoint' => null,
                'wireguard_cidr' => '10.6.0.0/24',
                'wireguard_port' => 51820,
                'dns_ip' => '10.6.0.1',
            ]);
    });

    it('hydrates explicit vpn settings', function (): void {
        $settings = (new NodeRoleRegistry)
            ->definition('vpn')
            ->settingsFromArray([
                'public_endpoint' => ' vpn.example.com ',
                'wireguard_cidr' => ' 10.44.0.0/24 ',
                'wireguard_port' => 51821,
                'dns_ip' => ' 10.44.0.1 ',
            ]);

        expect($settings)
            ->toBeInstanceOf(VpnRoleSettings::class)
            ->and($settings->toArray())
            ->toBe([
                'public_endpoint' => 'vpn.example.com',
                'wireguard_cidr' => '10.44.0.0/24',
                'wireguard_port' => 51821,
                'dns_ip' => '10.44.0.1',
            ]);
    });

    it('hydrates empty settings dtos for roles without settings', function (string $role, string $class): void {
        $settings = (new NodeRoleRegistry)
            ->definition($role)
            ->settingsFromArray([]);

        expect($settings)
            ->toBeInstanceOf($class)
            ->and($settings->toArray())
            ->toBe([]);
    })->with([
        ['gateway', EmptyRoleSettings::class],
        ['app-production', AppProductionRoleSettings::class],
        ['database', DatabaseRoleSettings::class],
    ]);

    it('rejects invalid app development settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('app-development')
            ->settingsFromArray(['tld' => '']))
            ->toThrow(InvalidArgumentException::class, 'The app-development role requires a valid tld setting.');
    });

    it('rejects path-like app development tld settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('app-development')
            ->settingsFromArray(['tld' => '../../orbit']))
            ->toThrow(InvalidArgumentException::class, 'The app-development role requires a valid tld setting.');
    });

    it('rejects unknown app development settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('app-development')
            ->settingsFromArray(['tld' => 'test', 'unexpected' => 'value']))
            ->toThrow(InvalidArgumentException::class, 'The app-development role does not accept unknown settings.');
    });

    it('rejects invalid agent settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray(['tld' => '']))
            ->toThrow(InvalidArgumentException::class, 'The agent role requires a valid tld setting.');
    });

    it('rejects path-like agent tld settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray(['tld' => '../../orbit']))
            ->toThrow(InvalidArgumentException::class, 'The agent role requires a valid tld setting.');
    });

    it('rejects unknown agent settings', function (): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('agent')
            ->settingsFromArray(['tld' => 'test', 'unexpected' => 'value']))
            ->toThrow(InvalidArgumentException::class, 'The agent role does not accept unknown settings.');
    });

    it('rejects invalid vpn settings', function (array $settings, string $message): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition('vpn')
            ->settingsFromArray($settings))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'unknown key' => [['unexpected' => 'value'], 'The vpn role does not accept unknown settings.'],
        'bad endpoint' => [['public_endpoint' => 'not-a-dotted-host'], 'The vpn role requires a valid public endpoint setting.'],
        'bad cidr' => [['wireguard_cidr' => '10.6.0.0'], 'The vpn role requires a valid IPv4 CIDR setting.'],
        'bad port' => [['wireguard_port' => 70000], 'The vpn role requires a valid WireGuard port.'],
        'bad dns' => [['dns_ip' => 'not-an-ip'], 'The vpn role requires a valid DNS IP setting.'],
    ]);

    it('rejects invalid vpn constructor values', function (?string $publicEndpoint, string $wireguardCidr, int $wireguardPort, string $dnsIp, string $message): void {
        expect(fn () => new VpnRoleSettings(
            publicEndpoint: $publicEndpoint,
            wireguardCidr: $wireguardCidr,
            wireguardPort: $wireguardPort,
            dnsIp: $dnsIp,
        ))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'bad endpoint' => ['not-a-dotted-host', '10.6.0.0/24', 51820, '10.6.0.1', 'The vpn role requires a valid public endpoint setting.'],
        'bad cidr' => ['203.0.113.10', '10.6.0.0/024', 51820, '10.6.0.1', 'The vpn role requires a valid IPv4 CIDR setting.'],
        'bad port' => ['203.0.113.10', '10.6.0.0/24', 70000, '10.6.0.1', 'The vpn role requires a valid WireGuard port.'],
        'bad dns' => ['203.0.113.10', '10.6.0.0/24', 51820, 'not-an-ip', 'The vpn role requires a valid DNS IP setting.'],
    ]);

    it('rejects settings for roles without settings', function (string $role): void {
        expect(fn () => (new NodeRoleRegistry)
            ->definition($role)
            ->settingsFromArray(['unexpected' => 'value']))
            ->toThrow(InvalidArgumentException::class, 'This role does not accept settings.');
    })->with(['gateway', 'app-production', 'database']);

    it('rejects unknown roles', function (): void {
        expect(fn () => (new NodeRoleRegistry)->definition('queue'))
            ->toThrow(InvalidArgumentException::class, 'Unknown node role [queue].');
    });

    it('defines the node role name enum values', function (): void {
        expect(array_map(
            static fn (NodeRoleName $role): string => $role->value,
            NodeRoleName::cases(),
        ))->toBe([
            'gateway',
            'vpn',
            'app-development',
            'app-production',
            'database',
            'agent',
        ]);
    });

    it('defines the node role status enum values', function (): void {
        expect(array_map(
            static fn (NodeRoleStatus $status): string => $status->value,
            NodeRoleStatus::cases(),
        ))->toBe([
            'pending',
            'active',
            'error',
            'removing',
        ]);
    });
});
