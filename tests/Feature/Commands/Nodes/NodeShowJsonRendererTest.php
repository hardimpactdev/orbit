<?php

declare(strict_types=1);

use App\Console\Commands\NodeShowCommand;
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
function nodeShowJsonRow(array $overrides = []): array
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

function invokeNodeShowFailCommand(bool $json, string $code, string $message, array $meta): array
{
    $command = new NodeShowCommand;
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

describe('node:show JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success'])->toBeArray()
            ->and($payload['success'])->toHaveKey('data');
    });

    it('returns success.data.node with all documented fields', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $node = $payload['success']['data']['node'];

        expect($node)->toHaveKey('name')
            ->and($node['name'])->toBe('app-1')
            ->and($node)->toHaveKey('role')
            ->and($node['role'])->toBe('app')
            ->and($node)->toHaveKey('status')
            ->and($node['status'])->toBe('active')
            ->and($node)->toHaveKey('environment')
            ->and($node['environment'])->toBe('development')
            ->and($node)->toHaveKey('platform')
            ->and($node['platform'])->toBe('ubuntu_24-04')
            ->and($node)->toHaveKey('addresses')
            ->and($node['addresses'])->toBeArray()
            ->and($node['addresses'])->toHaveKey('wireguard')
            ->and($node['addresses']['wireguard'])->toBe('10.6.0.7')
            ->and($node)->toHaveKey('agent_ide')
            ->and($node['agent_ide'])->toBeArray()
            ->and($node['agent_ide'])->toHaveKey('adapter')
            ->and($node['agent_ide']['adapter'])->toBeNull()
            ->and($node['agent_ide'])->toHaveKey('source')
            ->and($node['agent_ide']['source'])->toBe('default')
            ->and($node)->toHaveKey('grants')
            ->and($node['grants'])->toBeArray()
            ->and($node['grants'])->toHaveKey('consuming_nodes')
            ->and($node['grants']['consuming_nodes'])->toBeArray()
            ->and($node['grants'])->toHaveKey('serving_nodes')
            ->and($node['grants']['serving_nodes'])->toBeArray();
    });

    it('returns environment null for non-app roles', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => 'should-be-ignored',
        ]));

        $exitCode = Artisan::call('node:show', ['name' => 'gateway-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['environment'])->toBeNull()
            ->and($payload['success']['data']['node']['role'])->toBe('gateway');
    });

    it('defaults platform to unknown when null', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'platform' => null,
        ]));

        $exitCode = Artisan::call('node:show', ['name' => 'gateway-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['platform'])->toBe('unknown');
    });

    it('falls back to host when wireguard_address is null', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow([
            'wireguard_address' => null,
            'host' => '192.168.1.1',
        ]));

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['addresses']['wireguard'])->toBe('192.168.1.1');
    });

    it('returns agent_ide with adapter null and source default for bootstrap nodes', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $agentIde = $payload['success']['data']['node']['agent_ide'];

        expect($agentIde)->toBe([
            'adapter' => null,
            'source' => 'default',
        ]);
    });

    it('returns grants with empty consuming_nodes and serving_nodes arrays', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $grants = $payload['success']['data']['node']['grants'];

        expect($grants)->toBe([
            'consuming_nodes' => [],
            'serving_nodes' => [],
        ]);
    });

    it('has no live-check fields', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);

        $node = $payload['success']['data']['node'];

        $liveCheckFields = [
            'ssh_reachable',
            'wireguard_status',
            'gateway_api_status',
            'readiness',
            'drift',
            'platform_status',
            'checks',
        ];

        foreach ($liveCheckFields as $field) {
            expect($node)->not->toHaveKey($field);
        }
    });

    it('returns validation_failed error when name is missing and no local node exists', function (): void {
        $exitCode = Artisan::call('node:show', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('validation_failed')
            ->and($error['message'])->toBe('Node name is required.')
            ->and($error['meta'])->toBe(['field' => 'name']);
    });

    it('returns node.not_found error when node is missing', function (): void {
        $exitCode = Artisan::call('node:show', ['name' => 'missing-node', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('node.not_found')
            ->and($error['message'])->toBe("Node 'missing-node' not found or not visible.")
            ->and($error['meta'])->toBe(['name' => 'missing-node']);
    });

    it('uses correct enum values for role', function (): void {
        DB::table('nodes')->insert([
            nodeShowJsonRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
            ]),
            nodeShowJsonRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
            ]),
            nodeShowJsonRow([
                'name' => 'control-1',
                'role' => 'control',
                'environment' => null,
            ]),
        ]);

        foreach (['gateway-1', 'app-1', 'control-1'] as $name) {
            $exitCode = Artisan::call('node:show', ['name' => $name, '--json' => true]);
            $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0);
            expect($payload['success']['data']['node']['role'])->toBeIn(['gateway', 'app', 'control']);
        }
    });

    it('platform is never null', function (): void {
        DB::table('nodes')->insert(nodeShowJsonRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
            'platform' => null,
        ]));

        $exitCode = Artisan::call('node:show', ['name' => 'gateway-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['platform'])->not->toBeNull();
    });

    it('returns authorization_failed error with correct metadata', function (): void {
        $result = invokeNodeShowFailCommand(
            json: true,
            code: 'authorization_failed',
            message: "This node is not authorized to inspect 'app-1'.",
            meta: ['name' => 'app-1', 'caller_role' => 'control'],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('authorization_failed')
            ->and($error['message'])->toBe("This node is not authorized to inspect 'app-1'.")
            ->and($error['meta'])->toBe(['name' => 'app-1', 'caller_role' => 'control']);
    });

    it('returns gateway_unavailable error with empty object metadata', function (): void {
        $result = invokeNodeShowFailCommand(
            json: true,
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to show node details.',
            meta: [],
        );

        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload)->not->toHaveKey('success');

        $error = $payload['error'];

        expect($error['code'])->toBe('gateway_unavailable')
            ->and($error['message'])->toBe('Gateway connection is required to show node details.')
            ->and($error['meta'])->toBe([]);
    });

    it('returns local_context_invalid error with correct metadata', function (): void {
        $result = invokeNodeShowFailCommand(
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
});
