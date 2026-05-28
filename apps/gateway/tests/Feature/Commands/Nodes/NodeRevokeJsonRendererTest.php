<?php

declare(strict_types=1);

use App\Console\Commands\NodeRevokeCommand;
use App\Models\NodeRoleAssignment;
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
function nodeRevokeJsonRow(array $overrides = []): array
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

function invokeNodeRevokeFailCommand(bool $json, string $code, string $message, array $meta, ?string $humanMessage = null): array
{
    $command = new NodeRevokeCommand;
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

function setupNodeRevokeGatewayCallerJson(): void
{
    config(['orbit.is_gateway' => true]);

    $nodeId = (int) DB::table('nodes')->insertGetId(nodeRevokeJsonRow([
        'name' => 'gateway-1',
    ]));
    assignNodeRevokeJsonRole($nodeId, 'gateway');
}

function assignNodeRevokeJsonRole(int $nodeId, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
    ]);
}

describe('node:revoke JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        setupNodeRevokeGatewayCallerJson();
        DB::table('nodes')->insert(nodeRevokeJsonRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeRevokeJsonRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success'])->toBeArray()
            ->and($payload['success'])->toHaveKey('data');
    });

    it('returns success with action revoked and already_absent false for new revoke', function (): void {
        setupNodeRevokeGatewayCallerJson();
        DB::table('nodes')->insert(nodeRevokeJsonRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeRevokeJsonRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => false,
                        'was_gateway_admin' => true,
                    ],
                ],
            ]);
    });

    it('returns success with action revoked and already_absent true for idempotent revoke', function (): void {
        setupNodeRevokeGatewayCallerJson();
        DB::table('nodes')->insert(nodeRevokeJsonRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeRevokeJsonRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => true,
                        'self_lockout' => false,
                        'was_gateway_admin' => false,
                    ],
                ],
            ]);
    });

    it('returns success with self_lockout true when revoking local gateway access', function (): void {
        config(['orbit.is_gateway' => true]);

        $consumerId = DB::table('nodes')->insertGetId(nodeRevokeJsonRow([
            'name' => 'self-lockout-test',
        ]));
        $servingId = DB::table('nodes')->insertGetId(nodeRevokeJsonRow([
            'name' => 'gateway-target',
        ]));
        assignNodeRevokeJsonRole($consumerId, 'gateway');
        assignNodeRevokeJsonRole($servingId, 'gateway');
        DB::table('node_access')->insert([
            'consumer_node_id' => $consumerId,
            'serving_node_id' => $servingId,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'self-lockout-test',
            'serving_node' => 'gateway-target',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['self_lockout'])->toBeTrue()
            ->and($payload['success']['data']['was_gateway_admin'])->toBeTrue()
            ->and($payload['success']['data']['action'])->toBe('revoked');
    });

    it('returns node.not_found error for consuming node with correct metadata', function (): void {
        setupNodeRevokeGatewayCallerJson();
        DB::table('nodes')->insert(nodeRevokeJsonRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'missing',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.not_found')
            ->and($error['message'])->toBe("Node 'missing' not found.")
            ->and($error['meta'])->toBe(['name' => 'missing']);
    });

    it('returns node.not_found error for serving node with correct metadata', function (): void {
        setupNodeRevokeGatewayCallerJson();
        DB::table('nodes')->insert(nodeRevokeJsonRow([
            'name' => 'control-1',
        ]));

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'missing',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.not_found')
            ->and($error['message'])->toBe("Node 'missing' not found.")
            ->and($error['meta'])->toBe(['name' => 'missing']);
    });

    it('returns validation_failed error for missing destructive consent', function (): void {
        setupNodeRevokeGatewayCallerJson();
        DB::table('nodes')->insert(nodeRevokeJsonRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeRevokeJsonRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('validation_failed')
            ->and($error['message'])->toBe('Use --force to revoke this grant.')
            ->and($error['meta'])->toBe(['field' => 'force']);
    });

    it('returns gateway_unavailable error with empty object metadata', function (): void {
        $result = invokeNodeRevokeFailCommand(
            json: true,
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to revoke a grant.',
            meta: [],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('gateway_unavailable')
            ->and($error['message'])->toBe('Gateway connection is required to revoke a grant.')
            ->and($error['meta'])->toBe([]);
    });

    it('returns authorization_failed error with correct metadata', function (): void {
        $result = invokeNodeRevokeFailCommand(
            json: true,
            code: 'authorization_failed',
            message: 'This action requires the node:revoke permission on a grant to the gateway.',
            meta: [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:revoke',
                'serving_node' => 'gateway-1',
            ],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('authorization_failed')
            ->and($error['message'])->toBe('This action requires the node:revoke permission on a grant to the gateway.')
            ->and($error['meta'])->toBe([
                'reason' => 'missing_permission',
                'missing_permission' => 'node:revoke',
                'serving_node' => 'gateway-1',
            ]);
    });

    it('uses correct enum value for action', function (): void {
        setupNodeRevokeGatewayCallerJson();
        DB::table('nodes')->insert(nodeRevokeJsonRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeRevokeJsonRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('revoked');
    });

    it('uses correct enum value for already_absent', function (): void {
        setupNodeRevokeGatewayCallerJson();
        DB::table('nodes')->insert(nodeRevokeJsonRow([
            'name' => 'control-1',
        ]));
        DB::table('nodes')->insert(nodeRevokeJsonRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['already_absent'])->toBeTrue();
    });
});
