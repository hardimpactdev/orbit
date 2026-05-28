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
            ->toBe(['orbit', 'node role:remove', 'app-1', 'database', '--json']);
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
