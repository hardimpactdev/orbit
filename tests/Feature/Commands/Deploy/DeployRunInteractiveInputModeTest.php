<?php

declare(strict_types=1);

use App\Console\Commands\DeployRunCommand;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\PromptAborted;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeployStep;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

#[Signature('deploy:run-test
    {app? : Production app name or domain}
    {--detach : Start and return after the run is durable}
    {--json : Output JSON}')]
#[Description('Testable deploy:run')]
class TestableDeployRunCommand extends DeployRunCommand
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
    TestableDeployRunCommand::$abortPrompt = null;
    TestableDeployRunCommand::$searchResults = [];

    config(['orbit.is_gateway' => true]);

    $fakeShell = new class implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: "deployed\n", stderr: '', durationMs: 10);
        }
    };
    app()->instance(RemoteShell::class, $fakeShell);
});

function makeProductionAppForRun(string $name = 'docs'): App
{
    $node = Node::factory()->create(['name' => 'run-app-node', 'role' => 'app']);

    $app = App::factory()->create([
        'name' => $name,
        'node_id' => $node->id,
        'environment' => 'production',
        'path' => '/srv/'.$name,
    ]);

    DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => 'Deploy',
        'command' => 'echo deployed',
        'sort_order' => 1,
        'timeout_seconds' => 60,
    ]);

    return $app;
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{int, string}
 */
function runDeployRunInteractive(array $arguments): array
{
    $command = new TestableDeployRunCommand;
    $command->setLaravel(app());

    $output = new BufferedOutput;
    $exitCode = $command->run(new ArrayInput($arguments), $output);

    return [$exitCode, $output->fetch()];
}

it('fails with validation_failed when app is missing in --json mode', function (): void {
    Artisan::call('deploy:run', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});

it('prompts for app when missing in interactive mode', function (): void {
    makeProductionAppForRun();

    TestableDeployRunCommand::$searchResults = ['App' => 'docs'];

    [$exitCode] = runDeployRunInteractive([]);

    expect($exitCode)->toBe(0)
        ->and(DeploymentRun::query()->count())->toBe(1);
});

it('does not prompt when app is provided in interactive mode', function (): void {
    makeProductionAppForRun();

    [$exitCode] = runDeployRunInteractive(['app' => 'docs']);

    expect($exitCode)->toBe(0)
        ->and(DeploymentRun::query()->count())->toBe(1);
});

it('cancels before creating a run when app prompt is aborted in interactive mode', function (): void {
    makeProductionAppForRun();

    TestableDeployRunCommand::$abortPrompt = 'App';

    [$exitCode, $output] = runDeployRunInteractive([]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Operation cancelled.')
        ->and(DeploymentRun::query()->count())->toBe(0);
});

it('does not prompt for --detach flag in interactive mode', function (): void {
    makeProductionAppForRun();

    TestableDeployRunCommand::$searchResults = ['App' => 'docs'];

    [$exitCode] = runDeployRunInteractive(['--detach' => true]);

    // --detach must be supplied explicitly and is never prompted
    expect($exitCode)->toBe(0);
});
