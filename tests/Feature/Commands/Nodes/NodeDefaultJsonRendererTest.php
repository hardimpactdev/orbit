<?php

declare(strict_types=1);

use App\Console\Commands\NodeDefaultCommand;
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
function nodeDefaultJsonRow(array $overrides = []): array
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
            ->and($error['message'])->toBe('Cannot provide both a node name and --clear.')
            ->and($error['meta'])->toBe(['fields' => ['name', 'clear']]);
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

    it('returns caller_role_not_allowed error with correct metadata', function (): void {
        DB::table('nodes')->insert(nodeDefaultJsonRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('caller_role_not_allowed')
            ->and($error['message'])->toBe('This command may only be run from a control node.')
            ->and($error['meta'])->toBe(['caller_role' => 'app']);
    });

    it('returns local_context_invalid error with correct metadata', function (): void {
        $result = invokeNodeDefaultFailCommand(
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
