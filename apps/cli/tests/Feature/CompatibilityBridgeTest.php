<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/NativeCommandNormalizer.php';

describe('compatibility bridge removal', function (): void {
    it('removes the bridge artifact from the CLI app', function (): void {
        expect(file_exists(dirname(__DIR__, 2).'/CompatibilityBridge.php'))->toBeFalse();
    });

    it('keeps the launcher pinned to native command normalization only', function (): void {
        $launcher = file_get_contents(dirname(__DIR__, 2).'/orbit');

        expect($launcher)
            ->toContain("__DIR__.'/NativeCommandNormalizer.php'")
            ->not->toContain('CompatibilityBridge.php');
    });
});

describe('native multi-token command normalization', function (): void {
    it('normalizes native multi-token read commands before Laravel Zero handles argv', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', 'node', 'role:list', 'gateway-1', '--json']))
            ->toBe(['orbit', 'node role:list', 'gateway-1', '--json']);
    });

    it('normalizes native multi-token write commands before Laravel Zero handles argv', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', 'node', 'role:add', 'app-1', 'app-dev', '--json']))
            ->toBe(['orbit', 'node role:add', 'app-1', 'app-dev', '--json'])
            ->and(normalizeNativeMultiTokenCommandArgv(['orbit', 'node', 'role:remove', 'app-1', 'database', '--json']))
            ->toBe(['orbit', 'node role:remove', 'app-1', 'database', '--json'])
            ->and(normalizeNativeMultiTokenCommandArgv([
                'orbit',
                'instance:analytics',
                'enable',
                'mealou-production',
                '--json',
            ]))
            ->toBe(['orbit', 'instance:analytics enable', 'mealou-production', '--json'])
            ->and(normalizeNativeMultiTokenCommandArgv([
                'orbit',
                'instance:analytics',
                'disable',
                'mealou-production',
                '--json',
            ]))
            ->toBe(['orbit', 'instance:analytics disable', 'mealou-production', '--json']);
    });

    it('normalizes native multi-token analytics reads before Laravel Zero handles argv', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv([
            'orbit',
            'instance:analytics',
            'show',
            'mealou-production',
            '--json',
        ]))
            ->toBe(['orbit', 'instance:analytics show', 'mealou-production', '--json']);
    });

    it('preserves leading options when normalizing native multi-token commands', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', '--no-interaction', 'node', 'role:list', 'gateway-1']))
            ->toBe(['orbit', '--no-interaction', 'node role:list', 'gateway-1']);
    });

    it('does not normalize command-looking arguments after the end-of-options marker', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', '--', 'node', 'role:list', 'gateway-1']))
            ->toBe(['orbit', '--', 'node', 'role:list', 'gateway-1']);
    });

    it('leaves unknown multi-token commands unchanged', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', 'node', 'role:sync', 'gateway-1', '--json']))
            ->toBe(['orbit', 'node', 'role:sync', 'gateway-1', '--json']);
    });

    it('leaves native single-token commands unchanged', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', 'node:list', '--json']))
            ->toBe(['orbit', 'node:list', '--json']);
    });

    it('returns the matched native multi-token command name', function (): void {
        expect(nativeMultiTokenCommandNameFromArgv(['orbit', '--json', 'node', 'role:add', 'app-1', 'app-dev']))
            ->toBe('node role:add')
            ->and(nativeMultiTokenCommandNameFromArgv(['orbit', 'node:list', '--json']))
            ->toBeNull();
    });
});

describe('native command option normalization', function (): void {
    it('rewrites root version options to the first-party version command', function (): void {
        expect(normalizeNativeCommandArgv(['orbit', '--version']))
            ->toBe(['orbit', 'version'])
            ->and(normalizeNativeCommandArgv(['orbit', '--version', '--json']))
            ->toBe(['orbit', 'version', '--json'])
            ->and(normalizeNativeCommandArgv(['orbit', '--version', '--local', '--json']))
            ->toBe(['orbit', 'version', '--local', '--json'])
            ->and(normalizeNativeCommandArgv(['orbit', '--json', '--version']))
            ->toBe(['orbit', 'version', '--json'])
            ->and(normalizeNativeCommandArgv(['orbit', '-V']))
            ->toBe(['orbit', 'version']);
    });

    it('rewrites tool install version options after the command name', function (): void {
        expect(normalizeNativeCommandArgv([
            'orbit',
            'tool:install',
            'mysql',
            '--version=8.4',
            '--runtime=docker-swarm',
        ]))
            ->toBe(['orbit', 'tool:install', 'mysql', '--tool-version=8.4', '--runtime=docker-swarm'])
            ->and(normalizeNativeCommandArgv(['orbit', 'tool:install', 'mysql', '--version', '8.4']))
            ->toBe(['orbit', 'tool:install', 'mysql', '--tool-version=8.4']);
    });

    it('rewrites analytics update version options after the command name', function (): void {
        expect(normalizeNativeCommandArgv(['orbit', 'analytics:update', '--version=3.2.2', '--json']))
            ->toBe(['orbit', 'analytics:update', '--requested-version=3.2.2', '--json'])
            ->and(normalizeNativeCommandArgv(['orbit', 'analytics:update', '--version', '3.2.2']))
            ->toBe(['orbit', 'analytics:update', '--requested-version=3.2.2']);
    });

    it('preserves the global version option when a command name is present', function (): void {
        expect(normalizeNativeCommandArgv(['orbit', '--version', 'tool:install', 'mysql']))
            ->toBe(['orbit', '--version', 'tool:install', 'mysql']);
    });

    it('rewrites process add version options after the command name', function (): void {
        expect(normalizeNativeCommandArgv([
            'orbit',
            'process:add',
            'mysql8',
            '--node=beast',
            '--service=mysql',
            '--runtime=docker',
            '--version=8.3',
        ]))
            ->toBe([
                'orbit',
                'process:add',
                'mysql8',
                '--node=beast',
                '--service=mysql',
                '--runtime=docker',
                '--service-version=8.3',
            ])
            ->and(normalizeNativeCommandArgv([
                'orbit',
                'process:add',
                'mysql8',
                '--node',
                'beast',
                '--service',
                'mysql',
                '--runtime',
                'docker',
                '--version',
                '8.3',
            ]))
            ->toBe([
                'orbit',
                'process:add',
                'mysql8',
                '--node',
                'beast',
                '--service',
                'mysql',
                '--runtime',
                'docker',
                '--service-version=8.3',
            ]);
    });
});
