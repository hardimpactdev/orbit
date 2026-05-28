<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/CompatibilityBridge.php';

describe('compatibility bridge command parsing', function (): void {
    it('extracts multi-token unported command names from argv', function (): void {
        expect(commandNameFromArgv(['orbit', 'node', 'role:list', 'gateway-1']))
            ->toBe('node');
    });

    it('does not treat bare node as an unported bridge command', function (): void {
        expect(isUnportedPublicCommand('node'))->toBeFalse()
            ->and(commandNameFromArgv(['orbit', 'node', 'role:list']))->toBe('node');
    });

    it('does not bridge ported node read commands', function (string $command): void {
        $argv = str_contains($command, ' ')
            ? ['orbit', ...explode(' ', $command), '--json']
            : ['orbit', $command, '--json'];

        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv($argv))->toBeNull();
    })->with([
        'node:list',
        'node:show',
        'node role:list',
        'node:agent-ide',
        'node:grant',
        'node:new',
        'node:permissions',
        'node:remove',
        'node:revoke',
        'node:update',
        'node role:add',
        'node role:remove',
    ]);

    it('normalizes native multi-token commands before Laravel Zero handles argv', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', 'node', 'role:list', 'gateway-1', '--json']))
            ->toBe(['orbit', 'node role:list', 'gateway-1', '--json']);
    });

    it('normalizes native multi-token node write commands before Laravel Zero handles argv', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', 'node', 'role:add', 'app-1', 'app-dev', '--json']))
            ->toBe(['orbit', 'node role:add', 'app-1', 'app-dev', '--json'])
            ->and(normalizeNativeMultiTokenCommandArgv(['orbit', 'node', 'role:remove', 'app-1', 'database', '--json']))
            ->toBe(['orbit', 'node role:remove', 'app-1', 'database', '--json']);
    });

    it('preserves leading options when normalizing native multi-token commands', function (): void {
        expect(normalizeNativeMultiTokenCommandArgv(['orbit', '--no-interaction', 'node', 'role:list', 'gateway-1']))
            ->toBe(['orbit', '--no-interaction', 'node role:list', 'gateway-1']);
    });

    it('still recognizes single-token allow-list commands', function (): void {
        expect(commandNameFromArgv(['orbit', 'node:list', '--json']))
            ->toBe('node:list')
            ->and(isUnportedPublicCommand('node:list'))->toBeFalse();
    });

    it('does not bridge ported activity commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'activity:list',
        'activity:show',
    ]);

    it('does not bridge ported agent ide commands', function (): void {
        expect(isUnportedPublicCommand('agent-ide:message'))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', 'agent-ide:message', '--json']))->toBeNull();
    });

    it('does not bridge ported app commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'app:agent-ide',
        'app:exec',
        'app:list',
        'app:new',
        'app:prune',
        'app:register',
        'app:remove',
        'app:root',
        'app:show',
        'app:worker',
    ]);

    it('does not bridge ported Cloudflare read commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'cf-dns:list',
        'cf-zone:list',
    ]);

    it('does not bridge ported Cloudflare commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'cf-cache-rule:add',
        'cf-cache-rule:remove',
        'cf-cache:flush',
        'cf-dns:add',
        'cf-dns:list',
        'cf-dns:remove',
        'cf-ssl:disable',
        'cf-ssl:enable',
        'cf-zone:list',
    ]);

    it('does not bridge ported firewall read commands', function (): void {
        expect(isUnportedPublicCommand('firewall:list'))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', 'firewall:list', '--json']))->toBeNull();
    });

    it('does not bridge ported gateway bootstrap commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'gateway:add',
        'gateway:trust',
    ]);

    it('does not bridge ported dns local commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'dns:list',
        'dns:resolve-tld',
    ]);

    it('does not bridge ported update commands', function (): void {
        expect(isUnportedPublicCommand('update'))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', 'update', '--json']))->toBeNull();
    });

    it('continues to bridge streamed update commands outside this slice', function (): void {
        expect(isUnportedPublicCommand('update:all'))->toBeTrue()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', 'update:all', '--json']))
            ->toBe(['update:all', '--json']);
    });

    it('does not bridge ported node:default command', function (): void {
        expect(isUnportedPublicCommand('node:default'))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', 'node:default', '--json']))->toBeNull();
    });

    it('does not bridge ported firewall commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'firewall:allow',
        'firewall:deny',
        'firewall:list',
        'firewall:remove',
    ]);

    it('does not bridge ported workspace commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'workspace:exec',
        'workspace:list',
        'workspace:show',
        'workspace:history',
        'workspace:new',
        'workspace:setup',
        'workspace:remove',
        'workspace-setup-step:add',
        'workspace-setup-step:list',
        'workspace-setup-step:remove',
        'workspace-teardown-step:add',
        'workspace-teardown-step:list',
        'workspace-teardown-step:remove',
    ]);

    it('continues to bridge workspace log outside this slice', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeTrue();
    })->with([
        'workspace:log',
    ]);

    it('does not bridge ported process commands by default', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'process:add',
        'process:edit',
        'process:list',
        'process:logs',
        'process:remove',
        'process:restart',
        'process:start',
        'process:stop',
    ]);

    it('keeps process:logs --follow in the bridge until the streaming phase', function (): void {
        expect(gatewayArtisanArgumentsFromArgv(['orbit', 'process:logs', 'vite', '--app=docs', '--follow']))
            ->toBe(['process:logs', 'vite', '--app=docs', '--follow']);
    });

    it('does not bridge ported proxy commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'proxy:add',
        'proxy:list',
        'proxy:remove',
    ]);

    it('does not bridge ported schedule commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'schedule:add',
        'schedule:list',
        'schedule:logs',
        'schedule:remove',
        'schedule:run',
        'schedule:show',
    ]);

    it('does not bridge ported tool read commands by default', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'tool:credentials',
        'tool:list',
        'tool:logs',
        'tool:show',
    ]);

    it('keeps tool:logs --follow in the bridge until the streaming phase', function (): void {
        expect(gatewayArtisanArgumentsFromArgv(['orbit', 'tool:logs', 'supervisor', '--node=app-1', '--follow']))
            ->toBe(['tool:logs', 'supervisor', '--node=app-1', '--follow']);
    });

    it('does not bridge ported tool write and lifecycle commands by default', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'tool:install',
        'tool:reconfigure',
        'tool:reload',
        'tool:remove',
        'tool:restart',
        'tool:start',
        'tool:stop',
        'tool:update',
    ]);

    it('does not bridge ported vpn commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'vpn-client:disable',
        'vpn-client:enable',
        'vpn-client:list',
        'vpn-client:new',
        'vpn-client:remove',
        'vpn-web-ui:change-password',
    ]);

    it('does not bridge ported php and database read commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'php:list',
        'php:use',
        'database:list',
        'database:show',
        'database:tables',
        'database:schema',
        'database:describe',
    ]);

    it('continues to bridge database write commands outside this slice', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeTrue();
    })->with([
        'database:add',
        'database:attach',
        'database:detach',
        'database:query',
        'database:remove',
        'database:update',
    ]);

    it('does not bridge ported deploy read commands', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeFalse()
            ->and(gatewayArtisanArgumentsFromArgv(['orbit', $command, '--json']))->toBeNull();
    })->with([
        'deploy:history',
        'deploy:step-list',
    ]);

    it('continues to bridge deploy write and log commands outside this slice', function (string $command): void {
        expect(isUnportedPublicCommand($command))->toBeTrue();
    })->with([
        'deploy:log',
        'deploy:run',
        'deploy:step-add',
        'deploy:step-remove',
    ]);
});

describe('compatibility bridge gateway argv rewrite', function (): void {
    it('does not collapse ported multi-token node commands into gateway artisan arguments', function (): void {
        expect(gatewayArtisanArgumentsFromArgv(['orbit', 'node', 'role:add', '--json']))
            ->toBeNull();
    });

    it('does not bridge ported multi-token node commands with positional args', function (): void {
        expect(gatewayArtisanArgumentsFromArgv(['orbit', 'node', 'role:add', 'gateway-1', 'gateway', '--json']))
            ->toBeNull();
    });

    it('does not bridge ported single-token node write commands', function (): void {
        expect(gatewayArtisanArgumentsFromArgv(['orbit', 'node:grant', '--json']))
            ->toBeNull();
    });

    it('quotes the combined command name as one shell argument for passthru', function (): void {
        $shellCommand = buildPassthruShellCommand(PHP_BINARY, [
            '/path/to/artisan',
            'node role:add',
            '--json',
        ]);

        expect($shellCommand)->toContain(escapeshellarg('node role:add'))
            ->and($shellCommand)->toContain(escapeshellarg('--json'));
    });

    it('returns null when the command is not on the bridge allow-list', function (): void {
        expect(gatewayArtisanArgumentsFromArgv(['orbit', 'node', 'show', '--json']))->toBeNull();
    });
});
