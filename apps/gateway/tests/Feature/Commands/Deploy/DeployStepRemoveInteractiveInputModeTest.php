<?php

declare(strict_types=1);

use App\Console\Commands\DeployStepRemoveCommand;
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

#[Signature('deploy:step-remove-test
    {app? : Production app name or domain}
    {step? : Deployment step id or exact title}
    {--force : Skip interactive confirmation}
    {--json : Output JSON}')]
#[Description('Testable deploy:step-remove')]
class TestableDeployStepRemoveCommand extends DeployStepRemoveCommand
{
    public static ?string $abortPrompt = null;

    public static array $searchResults = [];

    public static bool $confirmResult = true;

    protected function promptSearch(string $label, Closure $options, string $placeholder = ''): string
    {
        if (self::$abortPrompt === $label) {
            throw new PromptAborted;
        }

        return self::$searchResults[$label] ?? '';
    }

    protected function promptConfirm(string $label, bool $default = true): bool
    {
        if (self::$abortPrompt === 'confirm') {
            throw new PromptAborted;
        }

        return self::$confirmResult;
    }
}

beforeEach(function (): void {
    TestableDeployStepRemoveCommand::$abortPrompt = null;
    TestableDeployStepRemoveCommand::$searchResults = [];
    TestableDeployStepRemoveCommand::$confirmResult = true;

    config(['orbit.is_gateway' => true]);
});

function makeProductionAppForRemove(string $name = 'docs'): App
{
    $node = Node::factory()->create(['name' => 'remove-app-node']);

    return App::factory()->create([
        'name' => $name,
        'node_id' => $node->id,
        'path' => '/srv/'.$name,
    ]);
}

function makeDeployStepForRemove(App $app, string $title = 'Deploy'): DeployStep
{
    return DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => $title,
        'command' => 'php artisan migrate',
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{int, string}
 */
function runDeployStepRemoveInteractive(array $arguments): array
{
    $command = new TestableDeployStepRemoveCommand;
    $command->setLaravel(app());

    $output = new BufferedOutput;
    $exitCode = $command->run(new ArrayInput($arguments), $output);

    return [$exitCode, $output->fetch()];
}

it('fails with validation_failed when app is missing in --json mode', function (): void {
    Artisan::call('deploy:step-remove', ['step' => '1', '--force' => true, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});

it('fails with validation_failed when step is missing in --json mode', function (): void {
    makeProductionAppForRemove();

    Artisan::call('deploy:step-remove', ['app' => 'docs', '--force' => true, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('step');
});

it('prompts for app and step in interactive mode and removes with confirmation', function (): void {
    $app = makeProductionAppForRemove();
    $step = makeDeployStepForRemove($app);

    TestableDeployStepRemoveCommand::$searchResults = [
        'App' => 'docs',
        'Step' => (string) $step->id,
    ];
    TestableDeployStepRemoveCommand::$confirmResult = true;

    [$exitCode] = runDeployStepRemoveInteractive([]);

    expect($exitCode)->toBe(0)
        ->and(DeployStep::query()->count())->toBe(0);
});

it('does not prompt when app and step are provided and --force is supplied', function (): void {
    $app = makeProductionAppForRemove();
    makeDeployStepForRemove($app, 'Deploy');

    [$exitCode] = runDeployStepRemoveInteractive([
        'app' => 'docs',
        'step' => 'Deploy',
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(DeployStep::query()->count())->toBe(0);
});

it('cancels before removing when confirmation is declined in interactive mode', function (): void {
    $app = makeProductionAppForRemove();
    makeDeployStepForRemove($app);

    TestableDeployStepRemoveCommand::$confirmResult = false;

    [$exitCode, $output] = runDeployStepRemoveInteractive([
        'app' => 'docs',
        'step' => 'Deploy',
    ]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Use --force to remove')
        ->and(DeployStep::query()->count())->toBe(1);
});

it('cancels before removing when app prompt is aborted in interactive mode', function (): void {
    $app = makeProductionAppForRemove();
    makeDeployStepForRemove($app);

    TestableDeployStepRemoveCommand::$abortPrompt = 'App';

    [$exitCode, $output] = runDeployStepRemoveInteractive([]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Operation cancelled.')
        ->and(DeployStep::query()->count())->toBe(1);
});

it('cancels before removing when confirm prompt is aborted in interactive mode', function (): void {
    $app = makeProductionAppForRemove();
    makeDeployStepForRemove($app);

    TestableDeployStepRemoveCommand::$abortPrompt = 'confirm';

    [$exitCode, $output] = runDeployStepRemoveInteractive([
        'app' => 'docs',
        'step' => 'Deploy',
    ]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Operation cancelled.')
        ->and(DeployStep::query()->count())->toBe(1);
});
