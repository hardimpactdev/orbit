<?php

declare(strict_types=1);

use App\Console\Commands\NodeRemoveCommand;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeRemoveHumanRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function invokeNodeRemoveFailCommandHuman(string $code, string $message, array $meta, ?string $humanMessage = null): array
{
    $command = new NodeRemoveCommand;
    $command->setLaravel(app());
    $input = new ArrayInput([
        'name' => 'dummy',
    ]);
    $output = new BufferedOutput;

    $merge = new ReflectionMethod($command, 'mergeApplicationDefinition');
    $merge->setAccessible(true);
    $merge->invoke($command);

    $definition = $command->getDefinition();
    $input->bind($definition);
    $input->validate();

    $init = new ReflectionMethod($command, 'initialize');
    $init->setAccessible(true);
    $init->invoke($command, $input, $output);

    $inputProp = (new ReflectionClass(Command::class))->getProperty('input');
    $inputProp->setAccessible(true);
    $inputProp->setValue($command, $input);

    $outputProp = (new ReflectionClass(Command::class))->getProperty('output');
    $outputProp->setAccessible(true);
    $outputProp->setValue($command, $output);

    $method = new ReflectionMethod($command, 'failCommand');
    $method->setAccessible(true);
    $exitCode = $method->invoke($command, $code, $message, $meta, $humanMessage);

    return [
        'exitCode' => $exitCode,
        'output' => $output->fetch(),
    ];
}

function setupNodeRemoveGatewayCallerHuman(): void
{
    DB::table('nodes')->insert(nodeRemoveHumanRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:remove human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('renders progress tree', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌ Remove Node')
            ->and($output)->toContain('○ Validate removal')
            ->and($output)->toContain('○ Remove node grants')
            ->and($output)->toContain('○ Remove WireGuard peer')
            ->and($output)->toContain('○ Remove node record')
            ->and($output)->toContain('└ Node `app-1` removed');
    });

    it('renders removal success prose', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Node 'app-1' removed");
    });

    it('does not render self-removal prose on gateway-local path', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:remove', [
            'name' => 'control-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Node 'control-1' removed");
        expect($output)->not->toContain('This machine no longer has Orbit gateway access.');
    });

    it('renders node-not-found prose error', function (): void {
        setupNodeRemoveGatewayCallerHuman();

        $exitCode = Artisan::call('node:remove', [
            'name' => 'missing',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Node 'missing' not found.");
    });

    it('renders gateway-removal-denied prose error', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow([
            'name' => 'gateway-2',
            'role' => 'gateway',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:remove', [
            'name' => 'gateway-2',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('The gateway node cannot be removed with this command.');
    });

    it('renders missing-destructive-consent prose error', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Use --force to remove this node.');
    });

    it('renders gateway-unavailable prose error', function (): void {
        $result = invokeNodeRemoveFailCommandHuman(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to remove a node.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Gateway connection is required to remove a node.');
    });

    it('renders caller-role-not-allowed prose error for app caller', function (): void {
        DB::table('nodes')->insert(nodeRemoveHumanRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('This command may only be run from a control or gateway node.');
    });

    it('renders local-context-invalid prose error', function (): void {
        $result = invokeNodeRemoveFailCommandHuman(
            code: 'local_context_invalid',
            message: 'Local node role setting is invalid.',
            meta: ['setting' => 'general.local_node_role', 'reason' => 'unsupported_value', 'caller_role' => 'unknown'],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Local node role setting is invalid.');
    });

    it('renders authorization-failed prose error', function (): void {
        $result = invokeNodeRemoveFailCommandHuman(
            code: 'authorization_failed',
            message: 'This control node is not authorized to remove nodes.',
            meta: ['required_node' => 'gateway-1', 'caller_role' => 'control'],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('This control node is not authorized to remove nodes.');
    });

    it('renders operation-cancelled prose error', function (): void {
        $result = invokeNodeRemoveFailCommandHuman(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Operation cancelled.');
    });
});
