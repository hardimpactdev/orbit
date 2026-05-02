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

function setupGatewayCallerJson(): void
{
    DB::table('nodes')->insert(nodeGrantJsonRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:grant JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
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
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
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
                    ],
                ],
            ]);
    });

    it('returns success with action granted and already_granted true for idempotent grant', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
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
                    ],
                ],
            ]);
    });

    it('returns node.not_found error for consuming node with correct metadata', function (): void {
        setupGatewayCallerJson();
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
        setupGatewayCallerJson();
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

    it('returns node.grant_policy_violation error with reason self_grant', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.grant_policy_violation')
            ->and($error['message'])->toBe('A node cannot be granted access to itself.')
            ->and($error['meta'])->toBe([
                'consuming_node' => 'control-1',
                'serving_node' => 'control-1',
                'reason' => 'self_grant',
            ]);
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
            message: 'This control node is not authorized to grant node access.',
            meta: ['required_node' => 'gateway-1', 'caller_role' => 'control'],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('authorization_failed')
            ->and($error['message'])->toBe('This control node is not authorized to grant node access.')
            ->and($error['meta'])->toBe(['required_node' => 'gateway-1', 'caller_role' => 'control']);
    });

    it('returns caller_role_not_allowed error with correct metadata', function (): void {
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('caller_role_not_allowed')
            ->and($error['message'])->toBe('This command may only be run from a control or gateway node.')
            ->and($error['meta'])->toBe(['caller_role' => 'app']);
    });

    it('returns local_context_invalid error with correct metadata', function (): void {
        $result = invokeNodeGrantFailCommand(
            json: true,
            code: 'local_context_invalid',
            message: 'Local node role setting is invalid.',
            meta: ['setting' => 'general.local_node_role', 'reason' => 'unsupported_value', 'caller_role' => 'unknown'],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('local_context_invalid')
            ->and($error['message'])->toBe('Local node role setting is invalid.')
            ->and($error['meta'])->toBe(['setting' => 'general.local_node_role', 'reason' => 'unsupported_value', 'caller_role' => 'unknown']);
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

    it('uses correct enum value for action', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('granted');
    });

    it('uses correct enum value for already_granted', function (): void {
        setupGatewayCallerJson();
        DB::table('nodes')->insert(nodeGrantJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantJsonRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['already_granted'])->toBeTrue();
    });
});
