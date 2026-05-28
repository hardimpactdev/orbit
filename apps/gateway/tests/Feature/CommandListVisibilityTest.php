<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @return array<string, mixed>
 */
function gatewayCommandList(): array
{
    $process = new Process([PHP_BINARY, 'artisan', 'list', '--format=json'], base_path());
    $process->mustRun();

    return json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $commandList
 * @return list<string>
 */
function gatewayVisibleCommandNames(array $commandList): array
{
    return array_values(array_column(
        array_filter($commandList['commands'], fn (array $command): bool => ! ($command['hidden'] ?? false)),
        'name',
    ));
}

it('hides CLI-owned public product commands from the gateway command list', function (): void {
    $visible = gatewayVisibleCommandNames(gatewayCommandList());

    expect($visible)->not->toContain(
        'doctor',
        'profile',
        'update',
        'activity:list',
        'app:exec',
        'app:list',
        'app:new',
        'app:register',
        'app:root',
        'app:worker',
        'cf-dns:list',
        'database:list',
        'deploy:run',
        'dns:list',
        'firewall:list',
        'gateway:add',
        'node:list',
        'node:new',
        'node role:add',
        'node role:list',
        'process:list',
        'proxy:list',
        'schedule:add',
        'schedule:list',
        'schedule:run',
        'tool:list',
        'update:all',
        'vpn-client:list',
        'workspace:exec',
        'workspace:list',
    );
});

it('shows gateway runtime and maintenance commands in the command list', function (): void {
    $visible = gatewayVisibleCommandNames(gatewayCommandList());

    expect($visible)->toContain(
        'migrate',
        'migrate:status',
        'queue:work',
        'cache:clear',
        'db:show',
        'make:model',
        'schedule:work',
        'tinker',
        'docs',
        'librarian:lint',
        'e2e:test',
        'e2e:preflight',
        'orbit-scheduler',
        'orbit:internal:bake-app-node',
        'orbit:internal:bootstrap-gateway-local',
        'orbit:internal:node-register',
    )->not->toContain(
        'boost:install',
        'mcp:start',
        'pail',
        'roster:scan',
    );
});

it('keeps hidden framework commands directly invocable', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', 'help', 'migrate:status'], base_path());
    $process->mustRun();

    expect($process->isSuccessful())->toBeTrue();
});

it('keeps hidden product adapter commands directly invocable for gateway API call sites', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', 'help', 'app:root'], base_path());
    $process->mustRun();

    expect($process->getOutput())->toContain('app:root');
});

it('uses the Orbit CLI name independent of local environment drift', function (): void {
    $process = new Process([PHP_BINARY, 'artisan', '--version', '--no-ansi'], base_path(), [
        'APP_NAME' => 'Laravel',
    ]);
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('Orbit 0.1.0');
});
