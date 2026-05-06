<?php

declare(strict_types=1);

use App\Console\Commands\NodeRevokeCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[Signature('node:revoke
    {consuming_node? : Name of the node whose access is being revoked}
    {serving_node? : Name of the node providing access}
    {--force : Confirm destructive operation without prompting}
    {--json : Output as JSON}')]
#[Description('Revoke one node\'s access to another')]
class TestableNodeRevokeCommand extends NodeRevokeCommand
{
    protected function isInteractiveInput(): bool
    {
        return true;
    }
}

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeRevokeInteractiveRow(array $overrides = []): array
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

function setupNodeRevokeGatewayCallerInteractive(): void
{
    DB::table('nodes')->insert(nodeRevokeInteractiveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:revoke interactive input mode contract', function (): void {
    it('forces non-interactive mode when --json is present', function (): void {
        setupNodeRevokeGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRevokeInteractiveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeInteractiveRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');
    });

    it('bypasses confirmation prompt with --force in non-interactive mode', function (): void {
        setupNodeRevokeGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRevokeInteractiveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeInteractiveRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('cancels before revoking when interactive confirmation is declined', function (): void {
        setupNodeRevokeGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRevokeInteractiveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeInteractiveRow());
        DB::table('node_access')->insert([
            'consumer_node_id' => 2,
            'serving_node_id' => 3,
        ]);

        $this->artisan('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ])
            ->expectsConfirmation("Revoke access from 'control-1' to 'app-1'? This cannot be undone.", 'no')
            ->expectsOutputToContain('Operation cancelled.')
            ->assertExitCode(1);

        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('rejects app-node callers before prompts in interactive mode', function (): void {
        DB::table('nodes')->insert(nodeRevokeInteractiveRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));
        DB::table('nodes')->insert(nodeRevokeInteractiveRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(1);
        expect($output)->toContain('This command may only be run from a control or gateway node.');
    });

    it('renders normal confirmation message via reflection', function (): void {
        $command = new NodeRevokeCommand;
        $method = new ReflectionMethod($command, 'confirmationMessage');
        $method->setAccessible(true);

        $message = $method->invoke($command, 'control-1', 'app-1', false);

        expect($message)->toBe("Revoke access from 'control-1' to 'app-1'? This cannot be undone.");
    });

    it('renders self-lockout confirmation message via reflection', function (): void {
        $command = new NodeRevokeCommand;
        $method = new ReflectionMethod($command, 'confirmationMessage');
        $method->setAccessible(true);

        $message = $method->invoke($command, 'control-1', 'gateway-1', true);

        expect($message)->toBe('Revoke this control node\'s gateway access? This machine will lose Orbit gateway access.');
    });

    it('fails with validation_failed when consuming_node is missing in non-interactive mode', function (): void {
        setupNodeRevokeGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRevokeInteractiveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeInteractiveRow());

        $exitCode = Artisan::call('node:revoke', [
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Consuming node is required.')
            ->and($payload['error']['meta'])->toBe(['field' => 'consuming_node']);
    });

    it('fails with validation_failed when serving_node is missing in non-interactive mode', function (): void {
        setupNodeRevokeGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRevokeInteractiveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeInteractiveRow());

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Serving node is required.')
            ->and($payload['error']['meta'])->toBe(['field' => 'serving_node']);
    });

    it('uses the console interactive flag for destructive prompts', function (): void {
        $command = new NodeRevokeCommand;
        $command->setLaravel(app());

        $merge = new ReflectionMethod($command, 'mergeApplicationDefinition');
        $merge->setAccessible(true);
        $merge->invoke($command);

        $input = new ArrayInput([
            'consuming_node' => 'dummy',
            'serving_node' => 'dummy',
        ]);
        $input->bind($command->getDefinition());
        $input->validate();

        $init = new ReflectionMethod($command, 'initialize');
        $init->setAccessible(true);
        $init->invoke($command, $input, new BufferedOutput);

        $inputProp = (new ReflectionClass(Command::class))->getProperty('input');
        $inputProp->setAccessible(true);
        $inputProp->setValue($command, $input);

        $method = new ReflectionMethod($command, 'isInteractiveInput');
        $method->setAccessible(true);

        $isInteractive = $method->invoke($command);

        expect($isInteractive)->toBeTrue();
    });

    it('prompts for missing consuming_node in interactive mode instead of validation_failed', function (): void {
        setupNodeRevokeGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRevokeInteractiveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeInteractiveRow());

        $command = new TestableNodeRevokeCommand;
        $command->setLaravel(app());

        $input = new ArrayInput([
            'serving_node' => 'app-1',
            '--force' => true,
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

        // In interactive mode, missing consuming_node triggers a prompt
        // instead of immediately returning validation_failed.
        // Since prompts cannot receive input in tests, the command will
        // fail at the prompt stage, but NOT with the validation_failed message.
        try {
            $command->run($input, $output);
            $outputStr = $output->fetch();
        } catch (Throwable $e) {
            $outputStr = $e->getMessage();
        }

        expect($outputStr)->not->toContain('Consuming node is required.');
        expect($outputStr)->not->toContain('validation_failed');
    });

    it('prompts for missing serving_node in interactive mode instead of validation_failed', function (): void {
        setupNodeRevokeGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRevokeInteractiveRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeRevokeInteractiveRow());

        $command = new TestableNodeRevokeCommand;
        $command->setLaravel(app());

        $input = new ArrayInput([
            'consuming_node' => 'control-1',
            '--force' => true,
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

        try {
            $command->run($input, $output);
            $outputStr = $output->fetch();
        } catch (Throwable $e) {
            $outputStr = $e->getMessage();
        }

        expect($outputStr)->not->toContain('Serving node is required.');
        expect($outputStr)->not->toContain('validation_failed');
    });
});
