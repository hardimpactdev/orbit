<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\AgentRoleSettings;
use App\Data\Nodes\RoleSettings\AppDevelopmentRoleSettings;
use App\Data\Nodes\RoleSettings\AppProductionRoleSettings;
use App\Data\Nodes\RoleSettings\DatabaseRoleSettings;
use App\Data\Nodes\RoleSettings\EmptyRoleSettings;
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
        ]);

        expect($registry->definition('app-development')->conflictsWith)->toBe([
            'gateway',
            'app-production',
        ]);

        expect($registry->definition('app-production')->conflictsWith)->toBe([
            'gateway',
            'app-development',
        ]);

        expect($registry->definition('database')->conflictsWith)->toBe([
            'gateway',
        ]);

        expect($registry->definition('agent')->conflictsWith)->toBe([
            'gateway',
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
            ->and($registry->definition('agent')->assignableByNodeNew)->toBeTrue();
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
