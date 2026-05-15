<?php

declare(strict_types=1);

use App\Console\Commands\NodeDefaultCommand;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
    MockClient::destroyGlobal();
});

afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeDefaultJsonRow(array $overrides = []): array
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

function invokeNodeDefaultFailCommand(bool $json, string $code, string $message, array $meta): array
{
    $command = new NodeDefaultCommand;
    $command->setLaravel(app());
    $input = new ArrayInput($json ? ['--json' => true] : []);
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
    $exitCode = $method->invoke($command, $code, $message, $meta);

    return [
        'exitCode' => $exitCode,
        'output' => $output->fetch(),
    ];
}

/**
 * @param  array<string, mixed>|string  $nodeListBody
 */
function fakeNodeDefaultJsonGateway(array|string $nodeListBody, int $nodeListStatus = 200): MockClient
{
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    return MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make(nodeDefaultJsonIdentityEnvelope(), 200),
        ListNodesRequest::class => MockResponse::make($nodeListBody, $nodeListStatus),
    ]);
}

function nodeDefaultJsonIdentityEnvelope(): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => 'control-1',
                    'role' => 'control',
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                    'role' => 'gateway',
                ],
            ],
        ],
    ];
}

describe('node:default JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success'])->toBeArray()
            ->and($payload['success'])->toHaveKey('data');
    });

    it('returns show success with default_node object', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'show',
                        'default_node' => [
                            'name' => 'app-1',
                            'role' => 'app',
                            'environment' => 'development',
                        ],
                    ],
                ],
            ]);
    });

    it('returns show success with null default_node for empty state', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'show',
                        'default_node' => null,
                    ],
                ],
            ]);
    });

    it('omits meta from JSON success for show', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success'])->not->toHaveKey('meta');
    });

    it('returns set success with default_node object', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());

        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'set',
                        'default_node' => [
                            'name' => 'app-1',
                            'role' => 'app',
                            'environment' => 'development',
                        ],
                    ],
                ],
            ]);
    });

    it('omits meta from JSON success for set', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());

        Artisan::call('node:default', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success'])->not->toHaveKey('meta');
    });

    it('returns clear success with was_set true when default existed', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'clear',
                        'default_node' => null,
                    ],
                    'meta' => [
                        'was_set' => true,
                    ],
                ],
            ]);
    });

    it('returns clear success with was_set false when no default existed', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());

        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'clear',
                        'default_node' => null,
                    ],
                    'meta' => [
                        'was_set' => false,
                    ],
                ],
            ]);
    });

    it('returns validation_failed error with correct metadata', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--clear' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('validation_failed')
            ->and($error['message'])->toBe('Provide only one node target.')
            ->and($error['meta'])->toBe(['fields' => ['name', 'clear']]);
    });

    it('preserves authorization_failed from gateway validation', function (): void {
        fakeNodeDefaultJsonGateway([
            'error' => [
                'code' => 'authorization_failed',
                'message' => "This node is not authorized to operate on 'app-1'.",
                'meta' => [
                    'name' => 'app-1',
                    'caller_role' => 'control',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'authorization_failed',
                'message' => "This node is not authorized to operate on 'app-1'.",
                'meta' => [
                    'name' => 'app-1',
                    'caller_role' => 'control',
                ],
            ]);
    });

    it('preserves caller_role_not_allowed from gateway validation', function (): void {
        fakeNodeDefaultJsonGateway([
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'This command may only be run from a control node.',
                'meta' => [
                    'caller_role' => 'app',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'caller_role_not_allowed',
                'message' => 'This command may only be run from a control node.',
                'meta' => [
                    'caller_role' => 'app',
                ],
            ]);
    });

    it('renders gateway_unavailable for gateway failures without a structured error code', function (): void {
        fakeNodeDefaultJsonGateway('Service Unavailable', 503);

        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'gateway_unavailable',
                'message' => 'Gateway connection is required to set a default node.',
                'meta' => [],
            ]);
    });

    it('returns node.not_found error with correct metadata', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'missing',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.not_found')
            ->and($error['message'])->toBe("Node 'missing' not found or not visible.")
            ->and($error['meta'])->toBe(['name' => 'missing']);
    });

    it('returns node.invalid_role error with correct metadata', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:default', [
            'name' => 'gateway-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.invalid_role')
            ->and($error['message'])->toBe("Node 'gateway-1' is not a development app node.")
            ->and($error['meta'])->toBe([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'required_role' => 'app',
                'required_environment' => 'development',
            ]);
    });

    it('returns authorization_failed error with correct metadata', function (): void {
        $result = invokeNodeDefaultFailCommand(
            json: true,
            code: 'authorization_failed',
            message: "This node is not authorized to operate on 'app-1'.",
            meta: ['name' => 'app-1', 'caller_role' => 'control'],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('authorization_failed')
            ->and($error['message'])->toBe("This node is not authorized to operate on 'app-1'.")
            ->and($error['meta'])->toBe(['name' => 'app-1', 'caller_role' => 'control']);
    });

    it('returns gateway_unavailable error with empty object metadata', function (): void {
        $result = invokeNodeDefaultFailCommand(
            json: true,
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to set a default node.',
            meta: [],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('gateway_unavailable')
            ->and($error['message'])->toBe('Gateway connection is required to set a default node.')
            ->and($error['meta'])->toBe([]);
    });

    it('uses correct enum values for action', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('show');
    });

    it('uses correct enum values for default_node.role', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['default_node']['role'])->toBe('app');
    });

    it('uses correct enum values for default_node.environment', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['default_node']['environment'])->toBe('development');
    });
});
