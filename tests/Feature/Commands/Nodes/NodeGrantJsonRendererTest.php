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
function nodeGrantJsonRow(array $overrides = []): array
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

function invokeNodeGrantFailCommand(bool $json, string $code, string $message, array $meta, ?string $humanMessage = null): array
{
    $command = new NodeGrantCommand;
    $command->setLaravel(app());
    $input = new ArrayInput(array_merge([
        'consuming_node' => 'dummy-consumer',
        'serving_node' => 'dummy-serving',
    ], $json ? ['--json' => true] : []));
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

function setupGrantGatewayCallerJson(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeGrantJsonRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

describe('node:grant JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        setupGrantGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success'])->toBeArray()
            ->and($payload['success'])->toHaveKey('data');
    });

    it('returns success with action granted and already_granted false for new grant', function (): void {
        setupGrantGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'granted',
                        'already_granted' => false,
                        'permissions' => ['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read', 'tool:restart'],
                    ],
                ],
            ]);
    });

    it('returns success with action granted and already_granted true for idempotent grant', function (): void {
        setupGrantGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'granted',
                        'already_granted' => true,
                        'permissions' => ['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read', 'tool:restart'],
                    ],
                ],
            ]);
    });

    it('returns node.not_found error for consuming node with correct metadata', function (): void {
        setupGrantGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'missing',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.not_found')
            ->and($error['message'])->toBe("Consuming node 'missing' not found.")
            ->and($error['meta'])->toBe(['field' => 'consuming_node', 'name' => 'missing']);
    });

    it('returns node.not_found error for serving node with correct metadata', function (): void {
        setupGrantGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'missing',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.not_found')
            ->and($error['message'])->toBe("Serving node 'missing' not found.")
            ->and($error['meta'])->toBe(['field' => 'serving_node', 'name' => 'missing']);
    });

    it('returns success for self-grants', function (): void {
        setupGrantGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'control-1',
                        'action' => 'granted',
                        'already_granted' => false,
                        'permissions' => ['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read', 'tool:restart'],
                    ],
                ],
            ]);
    });

    it('uses correct enum value for action', function (): void {
        setupGrantGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('granted');
    });

    it('uses correct enum value for already_granted', function (): void {
        setupGrantGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['already_granted'])->toBeTrue();
    });

    it('returns gateway_unavailable error with empty object metadata', function (): void {
        $result = invokeNodeGrantFailCommand(
            json: true,
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to grant node access.',
            meta: [],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('gateway_unavailable')
            ->and($error['message'])->toBe('Gateway connection is required to grant node access.')
            ->and($error['meta'])->toBe([]);
    });

    it('returns authorization_failed error with correct metadata', function (): void {
        $result = invokeNodeGrantFailCommand(
            json: true,
            code: 'authorization_failed',
            message: 'This action requires the node:grant permission on a grant to the gateway.',
            meta: [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:grant',
                'serving_node' => 'gateway-1',
            ],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('authorization_failed')
            ->and($error['message'])->toBe('This action requires the node:grant permission on a grant to the gateway.')
            ->and($error['meta'])->toBe([
                'reason' => 'missing_permission',
                'missing_permission' => 'node:grant',
                'serving_node' => 'gateway-1',
            ]);
    });

    it('returns validation_failed error with correct metadata', function (): void {
        $result = invokeNodeGrantFailCommand(
            json: true,
            code: 'validation_failed',
            message: 'Consuming node is required.',
            meta: ['field' => 'consuming_node'],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('validation_failed')
            ->and($error['message'])->toBe('Consuming node is required.')
            ->and($error['meta'])->toBe(['field' => 'consuming_node']);
    });

});
