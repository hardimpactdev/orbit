<?php

declare(strict_types=1);

use App\Console\Commands\NodeGrantCommand;
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
function nodeGrantHumanRow(array $overrides = []): array
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

function invokeNodeGrantFailCommandHuman(string $code, string $message, array $meta, ?string $humanMessage = null): array
{
    $command = new NodeGrantCommand;
    $command->setLaravel(app());
    $input = new ArrayInput([
        'consuming_node' => 'dummy-consumer',
        'serving_node' => 'dummy-serving',
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

function setupGatewayCallerHuman(): void
{
    DB::table('nodes')->insert(nodeGrantHumanRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:grant human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('does not render a progress tree', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('┌')
            ->and($output)->not->toContain('└')
            ->and($output)->not->toContain('│')
            ->and($output)->not->toContain('○');
    });

    it('renders new grant success prose', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Granted 'control-1' access to 'app-1'");
    });

    it('renders already-granted success prose', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("'control-1' already has access to 'app-1'");
    });

    it('renders consuming-node-not-found prose error', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'missing',
            'serving_node' => 'app-1',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Consuming node 'missing' not found.");
    });

    it('renders serving-node-not-found prose error', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'missing',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Serving node 'missing' not found.");
    });

    it('renders grant policy violation prose error for self-grant', function (): void {
        setupGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Grant from 'control-1' to 'control-1' violates node access policy.");
    });

    it('renders gateway-unavailable prose error', function (): void {
        $result = invokeNodeGrantFailCommandHuman(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to grant node access.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Gateway connection is required to grant node access.');
    });

    it('renders caller-role-not-allowed prose error for app caller', function (): void {
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('This command may only be run from a control or gateway node.');
    });

    it('renders local-context-invalid prose error', function (): void {
        $result = invokeNodeGrantFailCommandHuman(
            code: 'local_context_invalid',
            message: 'Local node role setting is invalid.',
            meta: ['setting' => 'general.local_node_role', 'reason' => 'unsupported_value', 'caller_role' => 'unknown'],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Local node role setting is invalid.');
    });

    it('renders authorization-failed prose error', function (): void {
        $result = invokeNodeGrantFailCommandHuman(
            code: 'authorization_failed',
            message: 'This control node is not authorized to grant node access.',
            meta: ['required_node' => 'gateway-1', 'caller_role' => 'control'],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('This control node is not authorized to grant node access.');
    });
});
