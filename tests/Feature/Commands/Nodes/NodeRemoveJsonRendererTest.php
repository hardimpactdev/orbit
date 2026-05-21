<?php

declare(strict_types=1);

use App\Console\Commands\NodeRemoveCommand;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
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
function nodeRemoveJsonRow(array $overrides = []): array
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

function invokeNodeRemoveFailCommandJson(bool $json, string $code, string $message, array $meta, ?string $humanMessage = null): array
{
    $command = new NodeRemoveCommand;
    $command->setLaravel(app());
    $input = new ArrayInput(array_merge([
        'name' => 'dummy',
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

function setupNodeRemoveGatewayCallerJson(): void
{
    config(['orbit.is_gateway' => true]);

    $nodeId = (int) DB::table('nodes')->insertGetId(nodeRemoveJsonRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

/**
 * @return array{code: string, message: string, family: string, next_command: string}
 */
function nodeRemoveJsonDnsWarning(): array
{
    return [
        'code' => 'node.role_baseline_mismatch',
        'message' => 'Development DNS mapping could not be removed: file delete error',
        'family' => 'node',
        'next_command' => 'doctor --fix --family=node --restore',
    ];
}

describe('node:remove JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        setupNodeRemoveGatewayCallerJson();
        DB::table('nodes')->insert(nodeRemoveJsonRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
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

    it('returns success with action removed and peer_removed false', function (): void {
        setupNodeRemoveGatewayCallerJson();
        DB::table('nodes')->insert(nodeRemoveJsonRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 1,
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'name' => 'app-1',
                        'action' => 'removed',
                        'removed_self' => false,
                        'wireguard_peer_removed' => false,
                        'grants_removed' => 1,
                    ],
                ],
            ]);
    });

    it('returns success with removed_self false on gateway-local path', function (): void {
        setupNodeRemoveGatewayCallerJson();
        DB::table('nodes')->insert(nodeRemoveJsonRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:remove', [
            'name' => 'control-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['removed_self'])->toBeFalse()
            ->and($payload['success']['data']['name'])->toBe('control-1')
            ->and($payload['success']['data']['action'])->toBe('removed');
    });

    it('returns success meta warnings for development DNS cleanup drift', function (): void {
        setupNodeRemoveGatewayCallerJson();
        DB::table('nodes')->insert(nodeRemoveJsonRow([
            'tld' => 'test',
        ]));

        app()->instance(DevelopmentDnsMappingEnactor::class, new class extends DevelopmentDnsMappingEnactor
        {
            public function mappingFor(Node $node): ?array
            {
                if ($node->name === '') {
                    return null;
                }

                return [
                    'node' => 'app-1',
                    'tld' => 'test',
                    'domain' => '*.test',
                    'target' => '10.6.0.7',
                ];
            }

            public function remove(Node $node): array
            {
                return [
                    'status' => 'failed',
                    'changed' => false,
                    'domain' => '*.test',
                    'target' => '10.6.0.7',
                    'path' => '/tmp/test.conf',
                    'reason' => 'file delete error',
                ];
            }
        });

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['warnings'])->toBe([nodeRemoveJsonDnsWarning()]);
    });

    it('returns node.not_found error with correct metadata', function (): void {
        setupNodeRemoveGatewayCallerJson();

        $exitCode = Artisan::call('node:remove', [
            'name' => 'missing',
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

    it('returns node.gateway_removal_denied error with correct metadata', function (): void {
        setupNodeRemoveGatewayCallerJson();
        $gatewayId = (int) DB::table('nodes')->insertGetId(nodeRemoveJsonRow([
            'name' => 'gateway-2',
            'role' => 'gateway',
            'environment' => null,
        ]));
        NodeRoleAssignment::factory()->create([
            'node_id' => $gatewayId,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'gateway-2',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.gateway_removal_denied')
            ->and($error['message'])->toBe('The gateway node cannot be removed with this command.')
            ->and($error['meta'])->toBe(['name' => 'gateway-2', 'role' => 'gateway']);
    });

    it('returns validation_failed error for missing destructive consent', function (): void {
        setupNodeRemoveGatewayCallerJson();
        DB::table('nodes')->insert(nodeRemoveJsonRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('validation_failed')
            ->and($error['message'])->toBe('Use --force to remove this node.')
            ->and($error['meta'])->toBe(['field' => 'force']);
    });

    it('returns validation_failed error for missing name', function (): void {
        setupNodeRemoveGatewayCallerJson();
        DB::table('nodes')->insert(nodeRemoveJsonRow());

        $exitCode = Artisan::call('node:remove', [
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('validation_failed')
            ->and($error['message'])->toBe('Node name is required.')
            ->and($error['meta'])->toBe(['field' => 'name']);
    });

    it('returns gateway_unavailable error with empty object metadata', function (): void {
        $result = invokeNodeRemoveFailCommandJson(
            json: true,
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to remove a node.',
            meta: [],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('gateway_unavailable')
            ->and($error['message'])->toBe('Gateway connection is required to remove a node.')
            ->and($error['meta'])->toBe([]);
    });

    it('returns authorization_failed error with correct metadata', function (): void {
        $result = invokeNodeRemoveFailCommandJson(
            json: true,
            code: 'authorization_failed',
            message: "This node is not authorized for 'node:remove' on 'app-1'.",
            meta: [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:remove',
                'serving_node' => 'app-1',
            ],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('authorization_failed')
            ->and($error['message'])->toBe("This node is not authorized for 'node:remove' on 'app-1'.")
            ->and($error['meta'])->toBe([
                'reason' => 'missing_permission',
                'missing_permission' => 'node:remove',
                'serving_node' => 'app-1',
            ]);
    });

    it('uses correct enum value for action', function (): void {
        setupNodeRemoveGatewayCallerJson();
        DB::table('nodes')->insert(nodeRemoveJsonRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('removed');
    });

    it('uses correct enum value for peer_removed', function (): void {
        setupNodeRemoveGatewayCallerJson();
        DB::table('nodes')->insert(nodeRemoveJsonRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['wireguard_peer_removed'])->toBeFalse();
    });
});
