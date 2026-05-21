<?php

declare(strict_types=1);

use App\Console\Commands\NodeRevokeCommand;
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
function nodeRevokeHumanRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function invokeNodeRevokeFailCommandHuman(string $code, string $message, array $meta, ?string $humanMessage = null): array
{
    $command = new NodeRevokeCommand;
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

function setupNodeRevokeGatewayCallerHuman(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeRevokeHumanRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

describe('node:revoke human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        setupNodeRevokeGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRevokeHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeHumanRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('renders progress tree', function (): void {
        setupNodeRevokeGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRevokeHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeHumanRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌  Revoke Grant')
            ->and($output)->toContain('○  Validate nodes')
            ->and($output)->toContain('○  Verify authorization')
            ->and($output)->toContain('○  Revoke access')
            ->and($output)->toContain('└');
    });

    it('renders revoke success prose', function (): void {
        setupNodeRevokeGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRevokeHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeHumanRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Access from 'control-1' to 'app-1' revoked");
    });

    it('renders already-absent success prose', function (): void {
        setupNodeRevokeGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRevokeHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeHumanRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Access from 'control-1' to 'app-1' was already revoked");
    });

    it('renders self-lockout success prose', function (): void {
        config(['orbit.is_gateway' => true]);

        $consumerId = DB::table('nodes')->insertGetId(nodeRevokeHumanRow([
            'name' => 'self-lockout-test',
            'role' => 'gateway',
            'environment' => null,
        ]));
        $servingId = DB::table('nodes')->insertGetId(nodeRevokeHumanRow([
            'name' => 'gateway-target',
            'role' => 'gateway',
            'environment' => null,
        ]));
        DB::table('node_access')->insert([
            'consumer_node_id' => $consumerId,
            'serving_node_id' => $servingId,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'self-lockout-test',
            'serving_node' => 'gateway-target',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Access from 'self-lockout-test' to 'gateway-target' revoked")
            ->and($output)->toContain('This machine no longer has Orbit gateway access.');
    });

    it('renders consuming-node-not-found prose error', function (): void {
        setupNodeRevokeGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRevokeHumanRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'missing',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Node 'missing' not found.");
    });

    it('renders serving-node-not-found prose error', function (): void {
        setupNodeRevokeGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRevokeHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'missing',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Node 'missing' not found.");
    });

    it('renders missing-destructive-consent prose error', function (): void {
        setupNodeRevokeGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRevokeHumanRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeHumanRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Use --force to revoke this grant.');
    });

    it('renders gateway-unavailable prose error', function (): void {
        $result = invokeNodeRevokeFailCommandHuman(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to revoke a grant.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Gateway connection is required to revoke a grant.');
    });

    it('renders authorization-failed prose error', function (): void {
        $result = invokeNodeRevokeFailCommandHuman(
            code: 'authorization_failed',
            message: 'This action requires the node:revoke permission on a grant to the gateway.',
            meta: [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:revoke',
                'serving_node' => 'gateway-1',
            ],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('This action requires the node:revoke permission on a grant to the gateway.');
    });
});
