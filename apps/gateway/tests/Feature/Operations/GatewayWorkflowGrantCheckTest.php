<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\GatewayWorkflowGrantCheck;
use Symfony\Component\Process\Process;

it('checks intended policy against the booted Gateway declarations including contributions', function (): void {
    $definitions = app(DirectOperation::class)->grantDefinitions();
    $policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);

    expect((new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toBe(count(array_unique(array_column($definitions, 'workflow'))))
        ->toBeGreaterThan(0);
});

it('grants every workflow through one wildcard and refuses its absence', function (): void {
    $definitions = app(DirectOperation::class)->grantDefinitions();
    $policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);
    $policy['workflows'] = array_values(array_filter($policy['workflows'], fn (array $entry): bool => $entry['workflow'] !== '*'));

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'Missing the run and read grant on every workflow');
});

it('refuses an exact run and read grant that the wildcard already covers', function (): void {
    $definitions = app(DirectOperation::class)->grantDefinitions();
    $policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);
    $policy['workflows'][] = ['workflow' => 'operation-gateway-swamp-load', 'actions' => ['run', 'read']];

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'Only a declared schedule input producer');
});

it('still requires the exact write grant of a schedule input producer', function (): void {
    $definitions = app(DirectOperation::class)->grantDefinitions();
    $policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);
    $policy['workflows'] = array_values(array_filter($policy['workflows'], fn (array $entry): bool => $entry['workflow'] !== 'maintenance-projects'));

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'maintenance-projects');
});

it('runs the required registry entrypoint with intended grant validation without resource records', function (): void {
    $process = new Process([PHP_BINARY, base_path('../../bin/check-operation-kinds')], base_path(), [
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/orbit-grants-no-config-cache-'.getmypid().'.php',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
    ]);

    $process->run();

    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toContain('GATEWAY-WORKFLOW-GRANTS workflows=')
        ->toContain('principal=user:orbit-gateway policy=intended');
});

it('requires execution copy key intent from core and loaded extension declarations', function (string $kind): void {
    $definitions = app(DirectOperation::class)->grantDefinitions();
    $policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);
    $copy = $definitions[$kind]['execution_copies'][0];
    $policy['vaults'] = array_values(array_filter($policy['vaults'], fn (array $entry): bool => $entry['vault'] !== $copy['vault'] || $entry['key_pattern'] !== $copy['key_pattern']));

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'Execution copy vault intent');
})->with(['database.write', 'vault.sync', 'conn.pairing_revoke', 'node.ssh_access_converge', 'schedule.run', 'tasks.turn', 'tasks.publish']);
