<?php

declare(strict_types=1);

use App\Console\Commands\DeployLogCommand;
use App\Exceptions\PromptAborted;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeploymentRunStep;
use App\Models\DeployStep;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

#[Signature('deploy:log-test
    {app? : Production app name or domain}
    {run? : Deployment run id}
    {--step= : Deployment run step id}
    {--lines=500 : Lines per captured stream}
    {--json : Output JSON}')]
#[Description('Testable deploy:log')]
class TestableDeployLogCommand extends DeployLogCommand
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
}

beforeEach(function (): void {
    TestableDeployLogCommand::$abortPrompt = null;
    TestableDeployLogCommand::$searchResults = [];

    config(['orbit.is_gateway' => true]);
});

function makeProductionAppForLog(string $name = 'docs'): App
{
    $node = Node::factory()->appProd()->create(['name' => 'log-app-node']);

    return App::factory()->create([
        'name' => $name,
        'node_id' => $node->id,
        'environment' => 'production',
        'path' => '/srv/'.$name,
    ]);
}

function makeDeployRunForLog(App $app): DeploymentRun
{
    $step = DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => 'Migrate',
        'command' => 'php artisan migrate',
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);

    $run = DeploymentRun::query()->create([
        'app_id' => $app->id,
        'status' => 'completed',
        'exit_code' => 0,
        'context' => ['release' => 'r1'],
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    DeploymentRunStep::query()->create([
        'deployment_run_id' => $run->id,
        'deploy_step_id' => $step->id,
        'title' => 'Migrate',
        'command' => 'php artisan migrate',
        'status' => 'completed',
        'exit_code' => 0,
        'stdout' => 'Migrated.',
        'stderr' => '',
        'sort_order' => 1,
        'timeout_seconds' => 120,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    return $run;
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{int, string}
 */
function runDeployLogInteractive(array $arguments): array
{
    $command = new TestableDeployLogCommand;
    $command->setLaravel(app());

    $output = new BufferedOutput;
    $exitCode = $command->run(new ArrayInput($arguments), $output);

    return [$exitCode, $output->fetch()];
}

it('fails with validation_failed when app is missing in --json mode', function (): void {
    Artisan::call('deploy:log', ['run' => '1', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});

it('fails with validation_failed when run is missing even with app in --json mode', function (): void {
    makeProductionAppForLog();

    Artisan::call('deploy:log', ['app' => 'docs', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('run');
});

it('prompts for app when missing in interactive mode', function (): void {
    $app = makeProductionAppForLog();
    $run = makeDeployRunForLog($app);

    TestableDeployLogCommand::$searchResults = ['App' => 'docs'];

    [$exitCode] = runDeployLogInteractive(['run' => (string) $run->id]);

    expect($exitCode)->toBe(0);
});

it('does not prompt when app is provided in interactive mode', function (): void {
    $app = makeProductionAppForLog();
    $run = makeDeployRunForLog($app);

    [$exitCode] = runDeployLogInteractive(['app' => 'docs', 'run' => (string) $run->id]);

    expect($exitCode)->toBe(0);
});

it('cancels when app prompt is aborted in interactive mode', function (): void {
    makeProductionAppForLog();

    TestableDeployLogCommand::$abortPrompt = 'App';

    [$exitCode, $output] = runDeployLogInteractive(['run' => '1']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Operation cancelled.');
});
