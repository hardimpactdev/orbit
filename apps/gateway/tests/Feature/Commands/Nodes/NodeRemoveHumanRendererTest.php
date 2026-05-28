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
function nodeRemoveHumanRow(array $overrides = []): array
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

function invokeNodeRemoveFailCommandHuman(string $code, string $message, array $meta, ?string $humanMessage = null): array
{
    $command = new NodeRemoveCommand;
    $command->setLaravel(app());
    $input = new ArrayInput([
        'name' => 'dummy',
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

function setupNodeRemoveGatewayCallerHuman(): void
{
    config(['orbit.is_gateway' => true]);

    $nodeId = (int) DB::table('nodes')->insertGetId(nodeRemoveHumanRow([
        'name' => 'gateway-1',
    ]));

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

describe('node:remove human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('renders progress tree', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌  Remove Node')
            ->and($output)->toContain('○  Validate removal')
            ->and($output)->toContain('○  Remove node grants')
            ->and($output)->toContain('○  Remove WireGuard peer')
            ->and($output)->toContain('○  Remove node record')
            ->and($output)->toContain('└  Node `app-1` removed');
    });

    it('renders removal success prose', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Node 'app-1' removed");
    });

    it('does not render self-removal prose on gateway-local path', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow([
            'name' => 'control-1',
        ]));

        $exitCode = Artisan::call('node:remove', [
            'name' => 'control-1',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Node 'control-1' removed");
        expect($output)->not->toContain('This machine no longer has Orbit gateway access.');
    });

    it('renders development DNS drift prose with recovery guidance', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow([
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
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain("Node 'app-1' removed")
            ->and($output)->toContain('Drift detected: Development DNS: Development DNS mapping could not be removed: file delete error')
            ->and($output)->toContain('Run: orbit doctor --family=node --restore');
    });

    it('renders node-not-found prose error', function (): void {
        setupNodeRemoveGatewayCallerHuman();

        $exitCode = Artisan::call('node:remove', [
            'name' => 'missing',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Node 'missing' not found.");
    });

    it('renders gateway-removal-denied prose error', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        $gatewayId = (int) DB::table('nodes')->insertGetId(nodeRemoveHumanRow([
            'name' => 'gateway-2',
        ]));
        NodeRoleAssignment::factory()->create([
            'node_id' => $gatewayId,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'gateway-2',
            '--force' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('The gateway node cannot be removed with this command.');
    });

    it('renders missing-destructive-consent prose error', function (): void {
        setupNodeRemoveGatewayCallerHuman();
        DB::table('nodes')->insert(nodeRemoveHumanRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Use --force to remove this node.');
    });

    it('renders gateway-unavailable prose error', function (): void {
        $result = invokeNodeRemoveFailCommandHuman(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to remove a node.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Gateway connection is required to remove a node.');
    });

    it('renders authorization-failed prose error', function (): void {
        $result = invokeNodeRemoveFailCommandHuman(
            code: 'authorization_failed',
            message: "This node is not authorized for 'node:remove' on 'app-1'.",
            meta: [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:remove',
                'serving_node' => 'app-1',
            ],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain("This node is not authorized for 'node:remove' on 'app-1'.");
    });

    it('renders operation-cancelled prose error', function (): void {
        $result = invokeNodeRemoveFailCommandHuman(
            code: 'validation_failed',
            message: 'Operation cancelled.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Operation cancelled.');
    });
});
