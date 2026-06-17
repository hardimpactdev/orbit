<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @return array<string, mixed>
 */
function orbitCommandList(): array
{
    /** @var array<string, mixed>|null $commandList */
    static $commandList = null;

    if ($commandList !== null) {
        return $commandList;
    }

    $process = new Process([PHP_BINARY, 'orbit', 'list', '--format=json'], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(0, 'orbit list --format=json failed: '.$process->getErrorOutput());

    $commandList = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

    return $commandList;
}

/**
 * @param  array<string, mixed>  $commandList
 * @return array<string, mixed>|null
 */
function findCommandInList(array $commandList, string $name): ?array
{
    foreach ($commandList['commands'] as $command) {
        if ($command['name'] === $name) {
            return $command;
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $commandList
 * @return list<string>
 */
function visibleCommandNames(array $commandList): array
{
    return array_values(array_column(
        array_filter($commandList['commands'], fn (array $c): bool => ! ($c['hidden'] ?? false)),
        'name',
    ));
}

describe('command list visibility', function (): void {
    it('shows ported product commands as visible', function (): void {
        $list = orbitCommandList();
        $visible = visibleCommandNames($list);

        expect($visible)->toBe([
            'doctor',
            'profile',
            'update',
            'version',
            'activity:list',
            'activity:show',
            'agent-ide:message',
            'app:agent-ide',
            'app:list',
            'app:mount',
            'app:new',
            'app:prune',
            'app:register',
            'app:remove',
            'app:root',
            'app:show',
            'app:websocket credentials',
            'app:websocket disable',
            'app:websocket enable',
            'app:worker',
            'cf-cache:flush',
            'cf-cache-rule:add',
            'cf-cache-rule:remove',
            'cf-dns:add',
            'cf-dns:list',
            'cf-dns:remove',
            'cf-ssl:disable',
            'cf-ssl:enable',
            'cf-zone:list',
            'database:add',
            'database:attach',
            'database:describe',
            'database:detach',
            'database:list',
            'database:query',
            'database:remove',
            'database:schema',
            'database:show',
            'database:tables',
            'database:update',
            'deploy:history',
            'deploy:log',
            'deploy:run',
            'deploy:step-add',
            'deploy:step-list',
            'deploy:step-remove',
            'dns:list',
            'dns:resolve-tld',
            'firewall:allow',
            'firewall:deny',
            'firewall:list',
            'firewall:remove',
            'gateway:add',
            'gateway:list',
            'gateway:status',
            'gateway:trust',
            'gateway:use',
            'metrics:credentials',
            'metrics:disable',
            'metrics:enable',
            'metrics:status',
            'node:agent-ide',
            'node:default',
            'node:grant',
            'node:list',
            'node:new',
            'node:permissions',
            'node:remove',
            'node:revoke',
            'node:show',
            'node:update',
            'node role:add',
            'node role:list',
            'node role:remove',
            'php:list',
            'php:use',
            'process:add',
            'process:edit',
            'process:list',
            'process:logs',
            'process:remove',
            'process:restart',
            'process:start',
            'process:stop',
            'proxy:add',
            'proxy:list',
            'proxy:remove',
            's3:credentials',
            's3:publish',
            's3:unpublish',
            'schedule:add',
            'schedule:list',
            'schedule:logs',
            'schedule:remove',
            'schedule:run',
            'schedule:show',
            'tool:credentials',
            'tool:install',
            'tool:list',
            'tool:reconfigure',
            'tool:remove',
            'tool:show',
            'tool:update',
            'update:all',
            'vpn-client:disable',
            'vpn-client:enable',
            'vpn-client:list',
            'vpn-client:new',
            'vpn-client:remove',
            'vpn-web-ui:change-password',
            'workspace:history',
            'workspace:list',
            'workspace:log',
            'workspace:new',
            'workspace:remove',
            'workspace:setup',
            'workspace:show',
            'workspace-setup-step:add',
            'workspace-setup-step:list',
            'workspace-setup-step:remove',
            'workspace-teardown-step:add',
            'workspace-teardown-step:list',
            'workspace-teardown-step:remove',
        ]);
    });

    it('does not register command exec surfaces', function (): void {
        $list = orbitCommandList();
        $visible = visibleCommandNames($list);

        expect($visible)->not->toContain('app:exec', 'workspace:exec')
            ->and(findCommandInList($list, 'app:exec'))->toBeNull()
            ->and(findCommandInList($list, 'workspace:exec'))->toBeNull();
    });

    it('contains ported commands in the registered command set', function (string $name): void {
        $list = orbitCommandList();
        $command = findCommandInList($list, $name);

        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeFalse();
    })->with([
        'activity:list',
        'activity:show',
        'agent-ide:message',
        'app:agent-ide',
        'app:list',
        'app:mount',
        'app:new',
        'app:prune',
        'app:register',
        'app:remove',
        'app:root',
        'app:show',
        'app:websocket credentials',
        'app:websocket disable',
        'app:websocket enable',
        'app:worker',
        'cf-cache-rule:add',
        'cf-cache-rule:remove',
        'cf-cache:flush',
        'cf-dns:add',
        'cf-dns:list',
        'cf-dns:remove',
        'cf-ssl:disable',
        'cf-ssl:enable',
        'cf-zone:list',
        'database:add',
        'database:attach',
        'database:describe',
        'database:detach',
        'database:list',
        'database:query',
        'database:remove',
        'database:schema',
        'database:show',
        'database:tables',
        'database:update',
        'dns:list',
        'dns:resolve-tld',
        'deploy:history',
        'deploy:log',
        'deploy:run',
        'deploy:step-add',
        'deploy:step-list',
        'deploy:step-remove',
        'doctor',
        'profile',
        'firewall:allow',
        'firewall:deny',
        'firewall:list',
        'firewall:remove',
        'gateway:add',
        'gateway:list',
        'gateway:status',
        'gateway:trust',
        'gateway:use',
        'metrics:credentials',
        'metrics:disable',
        'metrics:enable',
        'metrics:status',
        'node:agent-ide',
        'node:default',
        'node:grant',
        'node:new',
        'node:permissions',
        'node:remove',
        'node:revoke',
        'node role:add',
        'node role:list',
        'node role:remove',
        'node:list',
        'node:show',
        'node:update',
        'php:list',
        'php:use',
        'process:add',
        'process:edit',
        'process:list',
        'process:logs',
        'process:remove',
        'process:restart',
        'process:start',
        'process:stop',
        'proxy:add',
        'proxy:list',
        'proxy:remove',
        's3:credentials',
        's3:publish',
        's3:unpublish',
        'schedule:add',
        'schedule:list',
        'schedule:logs',
        'schedule:remove',
        'schedule:run',
        'schedule:show',
        'tool:credentials',
        'tool:install',
        'tool:list',
        'tool:reconfigure',
        'tool:remove',
        'tool:show',
        'tool:update',
        'update:all',
        'vpn-client:disable',
        'vpn-client:enable',
        'vpn-client:list',
        'vpn-client:new',
        'vpn-client:remove',
        'vpn-web-ui:change-password',
        'update',
        'version',
        'workspace:history',
        'workspace:list',
        'workspace:log',
        'workspace:new',
        'workspace:remove',
        'workspace:setup',
        'workspace:show',
        'workspace-setup-step:add',
        'workspace-setup-step:list',
        'workspace-setup-step:remove',
        'workspace-teardown-step:add',
        'workspace-teardown-step:list',
        'workspace-teardown-step:remove',
    ]);

    it('hides internal:executor:verify', function (): void {
        $command = findCommandInList(orbitCommandList(), 'internal:executor:verify');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides internal:wg-easy:state', function (): void {
        $command = findCommandInList(orbitCommandList(), 'internal:wg-easy:state');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides internal:database-query-local', function (): void {
        $command = findCommandInList(orbitCommandList(), 'internal:database-query-local');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides internal:workspace-adapter:lookup', function (): void {
        $command = findCommandInList(orbitCommandList(), 'internal:workspace-adapter:lookup');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides internal:workspace-adapter:update', function (): void {
        $command = findCommandInList(orbitCommandList(), 'internal:workspace-adapter:update');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides make:command', function (): void {
        $command = findCommandInList(orbitCommandList(), 'make:command');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides make:test', function (): void {
        $command = findCommandInList(orbitCommandList(), 'make:test');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides app:build', function (): void {
        $command = findCommandInList(orbitCommandList(), 'app:build');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides app:install', function (): void {
        $command = findCommandInList(orbitCommandList(), 'app:install');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides app:rename', function (): void {
        $command = findCommandInList(orbitCommandList(), 'app:rename');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides test', function (): void {
        $command = findCommandInList(orbitCommandList(), 'test');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides vendor:publish', function (): void {
        $command = findCommandInList(orbitCommandList(), 'vendor:publish');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });

    it('hides stub:publish', function (): void {
        $command = findCommandInList(orbitCommandList(), 'stub:publish');
        expect($command)->not->toBeNull()
            ->and($command['hidden'] ?? false)->toBeTrue();
    });
});
