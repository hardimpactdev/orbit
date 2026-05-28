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
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
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

function setupGrantGatewayCallerHuman(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeGrantHumanRow([
        'name' => 'gateway-1',
    ]));
}

describe('node:grant human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        setupGrantGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('does not render a progress tree', function (): void {
        setupGrantGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('┌')
            ->and($output)->not->toContain('└')
            ->and($output)->not->toContain('│')
            ->and($output)->not->toContain('○');
    });

    it('renders new grant success prose with permissions', function (): void {
        setupGrantGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Granted 'control-1' access to 'app-1'")
            ->and($output)->toContain('Permissions:')
            ->and($output)->toContain('app:read');
    });

    it('renders already-granted success prose with follow-up and permissions', function (): void {
        setupGrantGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeGrantHumanRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("'control-1' already has access to 'app-1'")
            ->and($output)->toContain('Run `orbit node:permissions control-1 app-1` to view or modify permissions.')
            ->and($output)->toContain('Permissions:')
            ->and($output)->toContain('app:read');
    });

    it('renders consuming-node-not-found prose error', function (): void {
        setupGrantGatewayCallerHuman();
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
        setupGrantGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'missing',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Serving node 'missing' not found.");
    });

    it('renders new grant success prose for self-grant with permissions', function (): void {
        setupGrantGatewayCallerHuman();
        DB::table('nodes')->insert(nodeGrantHumanRow([
            'name' => 'control-1',
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
            '--preset' => 'operator',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Granted 'control-1' access to 'control-1'")
            ->and($output)->toContain('Permissions:')
            ->and($output)->toContain('app:read');
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

    it('renders authorization-failed prose error', function (): void {
        $result = invokeNodeGrantFailCommandHuman(
            code: 'authorization_failed',
            message: 'This action requires the node:grant permission on a grant to the gateway.',
            meta: [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:grant',
                'serving_node' => 'gateway-1',
            ],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('This action requires the node:grant permission on a grant to the gateway.');
    });
});
