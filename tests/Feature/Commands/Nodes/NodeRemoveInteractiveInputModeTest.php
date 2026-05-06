<?php

declare(strict_types=1);

use App\Console\Commands\NodeRemoveCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[Signature('node:remove
    {name? : Name of the node to remove}
    {--force : Confirm destructive operation without prompting}
    {--json : Output as JSON}')]
#[Description('Remove a node from the registry')]
class TestableNodeRemoveCommand extends NodeRemoveCommand
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
function nodeRemoveInteractiveRow(array $overrides = []): array
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

function setupNodeRemoveGatewayCallerInteractive(): void
{
    DB::table('nodes')->insert(nodeRemoveInteractiveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:remove interactive input mode contract', function (): void {
    it('forces non-interactive mode when --json is present', function (): void {
        setupNodeRemoveGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRemoveInteractiveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');
    });

    it('bypasses confirmation prompt with --force in non-interactive mode', function (): void {
        setupNodeRemoveGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRemoveInteractiveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();
    });

    it('cancels before removing when interactive confirmation is declined', function (): void {
        setupNodeRemoveGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRemoveInteractiveRow());

        $this->artisan('node:remove', [
            'name' => 'app-1',
        ])
            ->expectsConfirmation("Remove node 'app-1'? This cannot be undone.", 'no')
            ->expectsOutputToContain('Operation cancelled.')
            ->assertExitCode(1);

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeTrue();
    });

    it('rejects app-node callers before prompts in interactive mode', function (): void {
        DB::table('nodes')->insert(nodeRemoveInteractiveRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));
        DB::table('nodes')->insert(nodeRemoveInteractiveRow());

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(1);
        expect($output)->toContain('This command may only be run from a control or gateway node.');
    });

    it('renders normal confirmation message via reflection', function (): void {
        $command = new NodeRemoveCommand;

        $method = new ReflectionMethod($command, 'confirmationMessage');
        $method->setAccessible(true);

        $message = $method->invoke($command, 'app-1', false);

        expect($message)->toBe("Remove node 'app-1'? This cannot be undone.");
    });

    it('renders self-removal confirmation message via reflection', function (): void {
        $command = new NodeRemoveCommand;

        $method = new ReflectionMethod($command, 'confirmationMessage');
        $method->setAccessible(true);

        $message = $method->invoke($command, 'control-1', true);

        expect($message)->toBe('Remove this control node from the fleet? This machine will lose Orbit gateway access.');
    });

    it('fails with validation_failed when name is missing in non-interactive mode', function (): void {
        setupNodeRemoveGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRemoveInteractiveRow());

        $exitCode = Artisan::call('node:remove', [
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Node name is required.')
            ->and($payload['error']['meta'])->toBe(['field' => 'name']);
    });

    it('uses the console interactive flag for destructive prompts', function (): void {
        $command = new NodeRemoveCommand;
        $command->setLaravel(app());

        $merge = new ReflectionMethod($command, 'mergeApplicationDefinition');
        $merge->setAccessible(true);
        $merge->invoke($command);

        $input = new ArrayInput([
            'name' => 'dummy',
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

    it('prompts for missing name in interactive mode instead of validation_failed', function (): void {
        setupNodeRemoveGatewayCallerInteractive();
        DB::table('nodes')->insert(nodeRemoveInteractiveRow());

        $command = new TestableNodeRemoveCommand;
        $command->setLaravel(app());

        $input = new ArrayInput([
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

        // In interactive mode, missing name triggers a prompt
        // instead of immediately returning validation_failed.
        // Since prompts cannot receive input in tests, the command will
        // fail at the prompt stage, but NOT with the validation_failed message.
        try {
            $command->run($input, $output);
            $outputStr = $output->fetch();
        } catch (Throwable $e) {
            $outputStr = $e->getMessage();
        }

        expect($outputStr)->not->toContain('Node name is required.');
        expect($outputStr)->not->toContain('validation_failed');
    });
});
