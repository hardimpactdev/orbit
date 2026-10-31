<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\GatewayWorkflowGrantCheck;
use Symfony\Component\Process\Process;

it('checks intended policy against the booted Gateway declarations including contributions', function (): void {
    $definitions = app(DirectOperation::class)->definitions();
    $policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);

    expect((new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toBe(count(array_unique(array_column($definitions, 'workflow'))))
        ->toBeGreaterThan(0);
});

it('refuses missing intent for booted core, computed and extension declarations', function (string $workflow): void {
    $definitions = app(DirectOperation::class)->definitions();
    $policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);
    $policy['workflows'] = array_values(array_filter($policy['workflows'], fn (array $entry): bool => $entry['workflow'] !== $workflow));

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, $workflow);
})->with([
    'Horizon' => 'operation-process-horizon-handoff',
    'Schedule run' => 'operation-schedule-run',
    'computed bootstrap' => 'operation-node-bootstrap-probe',
    'Conn contribution' => 'operation-conn-direct',
    'Vault contribution' => 'operation-vault-direct',
]);

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
    $definitions = app(DirectOperation::class)->definitions();
    $policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);
    $copy = $definitions[$kind]['execution_copies'][0];
    $policy['vaults'] = array_values(array_filter($policy['vaults'], fn (array $entry): bool => $entry['vault'] !== $copy['vault'] || $entry['key_pattern'] !== $copy['key_pattern']));

    expect(fn () => (new GatewayWorkflowGrantCheck)->execute($definitions, $policy))
        ->toThrow(LogicException::class, 'Execution copy vault intent');
})->with(['database.write', 'vault.sync', 'conn.pairing_revoke', 'node.ssh_access_converge', 'schedule.run', 'tasks.turn']);
