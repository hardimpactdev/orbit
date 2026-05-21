<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Access\NodePermissionRegistry;

describe('node permission presets', function (): void {
    it('defines all expected preset names', function (): void {
        $presets = new NodePermissionPresets;

        expect($presets->names())->toBe([
            'agent-self',
            'operator',
            'read-only',
            'developer',
            'admin',
            'gateway-admin',
        ]);
    });

    it('throws for unknown preset', function (): void {
        expect(fn () => (new NodePermissionPresets)->permissions('unknown'))
            ->toThrow(InvalidArgumentException::class, 'Unknown preset [unknown].');
    });

    it('only returns registry-known permissions', function (): void {
        $presets = new NodePermissionPresets;
        $normalizer = new NodePermissionNormalizer(new NodePermissionRegistry);

        foreach ($presets->names() as $name) {
            expect(fn () => $normalizer->normalize($presets->permissions($name)))
                ->not->toThrow(InvalidArgumentException::class);
        }
    });

    describe('agent-self preset', function (): void {
        it('contains the expected permissions', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('agent-self');

            expect($permissions)->toContain('node:read')
                ->and($permissions)->toContain('tool:read')
                ->and($permissions)->toContain('tool:restart')
                ->and($permissions)->toContain('tool:update:agent-tools')
                ->and($permissions)->toContain('doctor:verify');
        });

        it('excludes credentials and install', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('agent-self');

            expect($permissions)->not->toContain('tool:credentials')
                ->and($permissions)->not->toContain('tool:install')
                ->and($permissions)->not->toContain('tool:remove')
                ->and($permissions)->not->toContain('tool:stop')
                ->and($permissions)->not->toContain('tool:reconfigure')
                ->and($permissions)->not->toContain('node:update')
                ->and($permissions)->not->toContain('firewall_rule:write')
                ->and($permissions)->not->toContain('doctor:restore')
                ->and($permissions)->not->toContain('doctor:adopt');
        });
    });

    describe('operator preset', function (): void {
        it('contains the expected permissions', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('operator');

            expect($permissions)->toContain('app:read')
                ->and($permissions)->toContain('database:read')
                ->and($permissions)->toContain('doctor:verify')
                ->and($permissions)->toContain('firewall_rule:read')
                ->and($permissions)->toContain('node:read')
                ->and($permissions)->toContain('tool:read')
                ->and($permissions)->toContain('tool:restart');
        });

        it('excludes firewall writes', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('operator');

            expect($permissions)->not->toContain('firewall_rule:write');
        });

        it('excludes doctor:restore and doctor:adopt', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('operator');

            expect($permissions)->not->toContain('doctor:restore')
                ->and($permissions)->not->toContain('doctor:adopt')
                ->and($permissions)->not->toContain('database:query')
                ->and($permissions)->not->toContain('database:query:write')
                ->and($permissions)->not->toContain('database:write');
        });
    });

    describe('gateway-admin preset', function (): void {
        it('expands to a single wildcard', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('gateway-admin');

            expect($permissions)->toBe(['*']);
        });
    });

    describe('read-only preset', function (): void {
        it('contains only read permissions', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('read-only');

            foreach ($permissions as $permission) {
                expect(str_contains($permission, ':read') || $permission === 'doctor:verify' || str_contains($permission, ':list') || str_contains($permission, ':resolve'))
                    ->toBeTrue("{$permission} should be a read-like permission");
            }
        });

        it('contains core read permissions', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('read-only');

            expect($permissions)->toContain('app:read')
                ->and($permissions)->toContain('database:read')
                ->and($permissions)->toContain('node:read')
                ->and($permissions)->toContain('tool:read')
                ->and($permissions)->toContain('doctor:verify')
                ->and($permissions)->toContain('firewall_rule:read');
        });
    });

    describe('developer preset', function (): void {
        it('includes app and workspace surfaces', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('developer');

            expect($permissions)->toContain('app:read')
                ->and($permissions)->toContain('app:write')
                ->and($permissions)->toContain('workspace:read')
                ->and($permissions)->toContain('workspace:write');
        });

        it('includes process and schedule surfaces', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('developer');

            expect($permissions)->toContain('process:read')
                ->and($permissions)->toContain('process:add')
                ->and($permissions)->toContain('schedule:read')
                ->and($permissions)->toContain('schedule:write');
        });

        it('includes proxy and deploy surfaces', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('developer');

            expect($permissions)->toContain('proxy:read')
                ->and($permissions)->toContain('proxy:add')
                ->and($permissions)->toContain('deploy:read')
                ->and($permissions)->toContain('deploy:run');
        });

        it('includes database registry and query surfaces', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('developer');

            expect($permissions)->toContain('database:read')
                ->and($permissions)->toContain('database:write')
                ->and($permissions)->toContain('database:query')
                ->and($permissions)->toContain('database:query:write');
        });

        it('includes tool surfaces for development', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('developer');

            expect($permissions)->toContain('tool:read')
                ->and($permissions)->toContain('tool:restart')
                ->and($permissions)->toContain('tool:update')
                ->and($permissions)->toContain('tool:install')
                ->and($permissions)->toContain('tool:remove');
        });

        it('includes agent ide messaging', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('developer');

            expect($permissions)->toContain('agent-ide:message');
        });
    });

    describe('admin preset', function (): void {
        it('includes full tool authority', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('admin');

            expect($permissions)->toContain('tool:read')
                ->and($permissions)->toContain('tool:restart')
                ->and($permissions)->toContain('tool:update')
                ->and($permissions)->toContain('tool:install')
                ->and($permissions)->toContain('tool:remove')
                ->and($permissions)->toContain('tool:credentials');
        });

        it('includes full firewall authority', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('admin');

            expect($permissions)->toContain('firewall_rule:read')
                ->and($permissions)->toContain('firewall_rule:write');
        });

        it('includes doctor restore and adopt', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('admin');

            expect($permissions)->toContain('doctor:restore')
                ->and($permissions)->toContain('doctor:adopt')
                ->and($permissions)->toContain('doctor:fix');
        });

        it('includes full database authority short of gateway admin', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('admin');

            expect($permissions)->toContain('database:read')
                ->and($permissions)->toContain('database:write')
                ->and($permissions)->toContain('database:query')
                ->and($permissions)->toContain('database:query:write');
        });

        it('excludes gateway-level node permissions', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('admin');

            expect($permissions)->not->toContain('node:grant')
                ->and($permissions)->not->toContain('node:revoke')
                ->and($permissions)->not->toContain('node:new')
                ->and($permissions)->not->toContain('node:remove')
                ->and($permissions)->not->toContain('node:migrate')
                ->and($permissions)->not->toContain('role:add')
                ->and($permissions)->not->toContain('role:update')
                ->and($permissions)->not->toContain('role:remove');
        });

        it('includes node:read for inspection', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('admin');

            expect($permissions)->toContain('node:read');
        });

        it('includes agent ide messaging', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('admin');

            expect($permissions)->toContain('agent-ide:message');
        });

        it('does not include wildcard', function (): void {
            $permissions = (new NodePermissionPresets)->permissions('admin');

            expect($permissions)->not->toContain('*');
        });
    });
});
