<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Symfony\Component\Process\Process;

/**
 * @return array{commands: list<array<string, mixed>>}
 */
function orbitCommandList(): array
{
    /** @var array{commands: list<array<string, mixed>>}|null $commandList */
    static $commandList = null;
    static $configPath = null;

    if ($commandList !== null) {
        return $commandList;
    }

    if ($configPath === null) {
        $configPath = orbit_test_config_path(prefix: 'orbit-command-list-base-');
        unlink_orbit_test_file($configPath);
    }

    $process = new Process([PHP_BINARY, 'orbit', 'list', '--format=json'], base_path(), [
        'ORBIT_CONFIG_PATH' => $configPath,
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(0, 'orbit list --format=json failed: '.$process->getErrorOutput());

    $commandList = decode_command_list($process->getOutput());

    return $commandList;
}

/**
 * @param  array<string, string>  $environment
 * @return array{commands: list<array<string, mixed>>}
 */
function orbit_command_list_with_environment(array $environment): array
{
    $process = new Process([PHP_BINARY, 'orbit', 'list', '--format=json'], base_path(), $environment);
    $process->run();

    expect($process->getExitCode())->toBe(0, 'orbit list --format=json failed: '.$process->getErrorOutput());

    return decode_command_list($process->getOutput());
}

/**
 * @return array{commands: list<array<string, mixed>>}
 */
function decode_command_list(string $output): array
{
    /** @var mixed $decoded */
    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    if (
        ! is_array($decoded)
        || ! array_key_exists('commands', $decoded)
        || ! is_array($decoded['commands'])
    ) {
        throw new RuntimeException('orbit list --format=json did not return a command list.');
    }

    /** @var array{commands: list<array<string, mixed>>} $decoded */
    return $decoded;
}

/**
 * @param  array{commands: list<array<string, mixed>>}  $commandList
 * @return array<string, mixed>|null
 */
function findCommandInList(array $commandList, string $name): ?array
{
    foreach ($commandList['commands'] as $command) {
        if (($command['name'] ?? null) === $name) {
            return $command;
        }
    }

    return null;
}

/**
 * @param  array{commands: list<array<string, mixed>>}  $commandList
 * @return list<string>
 */
function visibleCommandNames(array $commandList): array
{
    $names = [];

    foreach ($commandList['commands'] as $command) {
        if (($command['hidden'] ?? false) === true) {
            continue;
        }

        if (is_string($command['name'] ?? null)) {
            $names[] = $command['name'];
        }
    }

    return $names;
}

describe('command list visibility', function (): void {
    it('uses app and instance as the canonical workload namespaces', function (): void {
        $visible = visibleCommandNames(orbitCommandList());

        expect($visible)
            ->toContain(
                'app:new',
                'app:list',
                'app:show',
                'app:remove',
                'instance:list',
                'instance:show',
                'instance:add',
                'instance:remove',
                'instance:register',
                'instance:env',
                'instance:mount',
                'instance:root',
                'instance:setup',
                'instance:worker',
                'instance:analytics disable',
                'instance:analytics enable',
                'instance:analytics show',
                'instance:analytics verify',
                'instance:websocket credentials',
                'instance:websocket disable',
                'instance:websocket enable',
                'instance-setup-step:add',
                'instance-setup-step:list',
                'instance-setup-step:remove',
            )
            ->and(array_values(array_filter(
                $visible,
                fn (string $command): bool => (
                    str_starts_with($command, 'project:') || str_starts_with($command, 'project-setup-step:')
                ),
            )))
            ->toBe([]);
    });

    it('shows ported product commands as visible', function (): void {
        $list = orbitCommandList();
        $visible = visibleCommandNames($list);

        expect($visible)
            ->toContain(
                'doctor',
                'profile',
                'update',
                'version',
                'activity:list',
                'app:list',
                'app:new',
                'app:remove',
                'app:show',
                'instance:list',
                'instance:add',
                'instance:register',
                'workspace:list',
                'process:list',
                'proxy:list',
                'schedule:list',
                'deploy:run',
                'gateway:list',
                'node:list',
            )
            ->not->toContain(
                'project:list',
                'project:new',
                'project:show',
                'project:remove',
            );

        // Laravel Zero app:* package commands stay hidden.
        expect(array_values(array_filter(
            $visible,
            fn (string $command): bool => in_array($command, ['app:build', 'app:install', 'app:rename'], true),
        )))->toBeEmpty();
    });

    it('does not register the removed process:edit compatibility alias', function (): void {
        $list = orbitCommandList();

        expect(findCommandInList($list, 'process:edit'))->toBeNull();
    });

    it('does not register command exec surfaces', function (): void {
        $list = orbitCommandList();
        $visible = visibleCommandNames($list);

        expect($visible)
            ->not
            ->toContain('app:exec', 'workspace:exec')
            ->and(findCommandInList($list, 'app:exec'))
            ->toBeNull()
            ->and(findCommandInList($list, 'workspace:exec'))
            ->toBeNull();
    });

    it('does not expose node transport on commands with a fixed execution lane', function (string $commandName): void {
        $command = app(\Illuminate\Contracts\Console\Kernel::class)->all()[$commandName];

        expect($command->getDefinition()->hasOption('node-transport'))->toBeFalse();
    })->with([
        'tool:list',
        'firewall:list',
        'app:list',
        'workspace:list',
        'process:list',
        'proxy:list',
        'schedule:list',
        'activity:list',
        'database:list',
        'php:list',
        'php:use',
        'codex:app',
        'tool:credentials',
        'tool:install',
        'tool:reconfigure',
        'tool:remove',
        'tool:update',
    ]);

    it('hides Cloudflare commands until the local cloudflare extension is enabled', function (): void {
        $defaultConfigPath = orbit_test_config_path(prefix: 'orbit-command-list-default-');
        $enabledConfigPath = orbit_test_config_path(prefix: 'orbit-command-list-cloudflare-');

        unlink_orbit_test_file($defaultConfigPath);
        unlink_orbit_test_file($enabledConfigPath);

        try {
            $defaultVisible = visibleCommandNames(orbit_command_list_with_environment([
                'ORBIT_CONFIG_PATH' => $defaultConfigPath,
            ]));

            $store = new OrbitConfigStore(overridePath: $enabledConfigPath);
            $store->enableExtension('cloudflare');

            $enabledVisible = visibleCommandNames(orbit_command_list_with_environment([
                'ORBIT_CONFIG_PATH' => $enabledConfigPath,
            ]));

            expect($defaultVisible)
                ->not
                ->toContain('cf-zone:list', 'cf-dns:add')
                ->and($enabledVisible)
                ->toContain('cf-zone:list', 'cf-dns:add');
        } finally {
            unlink_orbit_test_file($defaultConfigPath);
            unlink_orbit_test_file($enabledConfigPath);
        }
    });

    it('hides app codex and shows codex app only when the local codex extension is enabled', function (): void {
        $defaultConfigPath = orbit_test_config_path(prefix: 'orbit-command-list-codex-default-');
        $enabledConfigPath = orbit_test_config_path(prefix: 'orbit-command-list-codex-');

        unlink_orbit_test_file($defaultConfigPath);
        unlink_orbit_test_file($enabledConfigPath);

        try {
            $defaultVisible = visibleCommandNames(orbit_command_list_with_environment([
                'ORBIT_CONFIG_PATH' => $defaultConfigPath,
            ]));

            $store = new OrbitConfigStore(overridePath: $enabledConfigPath);
            $store->enableExtension('codex');

            $enabledVisible = visibleCommandNames(orbit_command_list_with_environment([
                'ORBIT_CONFIG_PATH' => $enabledConfigPath,
            ]));

            expect(in_array('app:codex', $defaultVisible, strict: true))->toBeFalse();
            expect(in_array('codex:app', $defaultVisible, strict: true))->toBeFalse();
            expect($enabledVisible)->toContain('codex:app');
            expect(in_array('app:codex', $enabledVisible, strict: true))->toBeFalse();
        } finally {
            unlink_orbit_test_file($defaultConfigPath);
            unlink_orbit_test_file($enabledConfigPath);
        }
    });

    it('contains ported commands in the registered command set', function (string $name): void {
        $list = orbitCommandList();
        $command = findCommandInList($list, $name);

        expect($command)
            ->not
            ->toBeNull()
            ->and($command['hidden'] ?? false)
            ->toBeFalse();
    })->with([
        'activity:list',
        'activity:show',
        'instance:analytics disable',
        'instance:analytics enable',
        'instance:analytics show',
        'instance:analytics verify',
        'app:list',
        'instance:mount',
        'app:new',
        'instance:register',
        'app:remove',
        'instance:root',
        'instance:setup',
        'app:show',
        'instance-setup-step:add',
        'instance-setup-step:list',
        'instance-setup-step:remove',
        'instance:websocket credentials',
        'instance:websocket disable',
        'instance:websocket enable',
        'instance:worker',
        'database:add',
        'database:add-user',
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
        'extension:disable',
        'extension:enable',
        'extension:list',
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
        'manifest:remove',
        'manifest:update',
        'metrics:credentials',
        'metrics:disable',
        'metrics:enable',
        'metrics:status',
        'node:default',
        'node:grant',
        'node:manage',
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
        'process:list',
        'process:log',
        'process:remove',
        'process:restart',
        'process:start',
        'process:stop',
        'process:update',
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
        'skill:install',
        'tool:credentials',
        'tool:install',
        'tool:list',
        'tool:logs',
        'tool:reconfigure',
        'tool:reload',
        'tool:remove',
        'tool:restart',
        'tool:show',
        'tool:start',
        'tool:stop',
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
        'workspace:env',
        'workspace:history',
        'workspace:list',
        'workspace:run:log',
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
        'analytics:update',
    ]);

    it('hides non-product and internal commands', function (string $name): void {
        $command = findCommandInList(commandList: orbitCommandList(), name: $name);
        expect($command)
            ->not
            ->toBeNull()
            ->and($command['hidden'] ?? false)
            ->toBeTrue();
    })->with([
        'internal:executor:verify',
        'internal:wg-easy:state',
        'internal:agent-runtime:probe',
        'internal:database-query-local',
        'internal:database-add-user',
        'internal:app-cache:clear',
        'internal:app-introspect:probe',
        'internal:app-runtime-configs:probe',
        'internal:app-runtime-containers:probe',
        'internal:app-runtime-extensions:probe',
        'internal:app-source:create',
        'internal:app-source-path:probe',
        'internal:app-security:repair',
        'internal:app-worker-readiness:probe',
        'internal:caddy-config',
        'internal:codex-app-config',
        'internal:doctor-self',
        'internal:env-file',
        'internal:firewall-rule',
        'internal:firewall-rule:probe',
        'internal:fleet-update:install-cli',
        'internal:fleet-update:verify',
        'internal:gateway-runtime-backend:probe',
        'internal:managed-file',
        'internal:node-security-posture:probe',
        'internal:process-docker-container',
        'internal:process-docker-swarm-service',
        'internal:process-launchd-service',
        'internal:process-logs',
        'internal:process-systemd-service',
        'internal:runtime-backend:probe',
        'internal:s3-runtime:probe',
        'internal:site-certificate:install',
        'internal:unattended-upgrades:apply',
        'internal:unattended-upgrades:probe',
        'internal:websocket-runtime',
        'internal:wireguard-endpoint:rotate',
        'internal:wireguard-interface-public-key:read',
        'internal:wireguard-self-route',
        'internal:workspace-source:create',
        'make:command',
        'make:test',
        'app:build',
        'app:install',
        'app:rename',
        'test',
        'vendor:publish',
        'stub:publish',
    ]);
});
