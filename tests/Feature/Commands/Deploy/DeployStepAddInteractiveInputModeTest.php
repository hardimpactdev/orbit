<?php

declare(strict_types=1);

use App\Console\Commands\DeployStepAddCommand;
use App\Exceptions\PromptAborted;
use App\Models\App;
use App\Models\DeployStep;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

#[Signature('deploy:step-add-test
    {app? : Production app name or domain}
    {deploy_command? : Shell command to run}
    {--title= : Display title}
    {--order= : Positive insertion order}
    {--timeout=600 : Timeout in seconds}
    {--retention= : Optional release retention metadata}
    {--json : Output JSON}')]
#[Description('Testable deploy:step-add')]
class TestableDeployStepAddCommand extends DeployStepAddCommand
{
    public static ?string $abortPrompt = null;

    public static array $searchResults = [];

    protected function promptSearch(string $label, Closure $options, string $placeholder = ''): string
    {
        if (self::$abortPrompt === $label) {
            throw new PromptAborted;
        }

        return self::$searchResults[$label] ?? '';
    }

    protected function promptText(string $label, bool|string $required = false, mixed $validate = null): string
    {
        if (self::$abortPrompt === $label) {
            throw new PromptAborted;
        }

        return self::$searchResults[$label] ?? '';
    }

    public function isInteractiveForTest(): bool
    {
        return true;
    }
}

beforeEach(function (): void {
    TestableDeployStepAddCommand::$abortPrompt = null;
    TestableDeployStepAddCommand::$searchResults = [];

    config(['orbit.is_gateway' => true]);
    app()->extend(TestableDeployStepAddCommand::class, fn () => new TestableDeployStepAddCommand);
});

function makeProductionApp(string $name = 'docs'): App
{
    $node = Node::factory()->create(['name' => 'app-prod-1', 'role' => 'app']);

    return App::factory()->create([
        'name' => $name,
        'node_id' => $node->id,
        'environment' => 'production',
        'path' => '/srv/'.$name,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{int, string}
 */
function runDeployStepAddInteractive(array $arguments): array
{
    $command = new TestableDeployStepAddCommand;
    $command->setLaravel(app());

    $output = new BufferedOutput;
    $exitCode = $command->run(new ArrayInput($arguments), $output);

    return [$exitCode, $output->fetch()];
}

it('fails with validation_failed when app is missing in --json mode', function (): void {
    Artisan::call('deploy:step-add', [
        'deploy_command' => 'php artisan migrate',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});

it('fails with validation_failed when command is missing in --json mode', function (): void {
    makeProductionApp();

    Artisan::call('deploy:step-add', [
        'app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('command');
});

it('prompts for app and command when both are missing in interactive mode', function (): void {
    makeProductionApp();

    TestableDeployStepAddCommand::$searchResults = [
        'App' => 'docs',
        'Command' => 'php artisan migrate',
    ];

    [$exitCode] = runDeployStepAddInteractive([]);

    expect($exitCode)->toBe(0);
});

it('prompts only for command when app is provided in interactive mode', function (): void {
    makeProductionApp();

    TestableDeployStepAddCommand::$searchResults = [
        'Command' => 'php artisan migrate',
    ];

    [$exitCode] = runDeployStepAddInteractive(['app' => 'docs']);

    expect($exitCode)->toBe(0);
});

it('does not prompt when both app and command are provided in interactive mode', function (): void {
    makeProductionApp();

    [$exitCode] = runDeployStepAddInteractive([
        'app' => 'docs',
        'deploy_command' => 'php artisan migrate',
    ]);

    expect($exitCode)->toBe(0);
});

it('cancels before writing when app prompt is aborted in interactive mode', function (): void {
    makeProductionApp();

    TestableDeployStepAddCommand::$abortPrompt = 'App';

    [$exitCode, $output] = runDeployStepAddInteractive([]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Operation cancelled.')
        ->and(DeployStep::query()->count())->toBe(0);
});

it('cancels before writing when command prompt is aborted in interactive mode', function (): void {
    makeProductionApp();

    TestableDeployStepAddCommand::$searchResults = ['App' => 'docs'];
    TestableDeployStepAddCommand::$abortPrompt = 'Command';

    [$exitCode, $output] = runDeployStepAddInteractive([]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Operation cancelled.')
        ->and(DeployStep::query()->count())->toBe(0);
});
