<?php

declare(strict_types=1);

use App\Console\Commands\DeployStepListCommand;
use App\Exceptions\PromptAborted;
use App\Models\App;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

#[Signature('deploy:step-list-test
    {app? : Production app name or domain}
    {--json : Output JSON}')]
#[Description('Testable deploy:step-list')]
class TestableDeployStepListCommand extends DeployStepListCommand
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
    TestableDeployStepListCommand::$abortPrompt = null;
    TestableDeployStepListCommand::$searchResults = [];

    config(['orbit.is_gateway' => true]);
});

function makeProductionAppForList(string $name = 'docs'): App
{
    $node = Node::factory()->appProd()->create(['name' => 'list-app-node']);

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
function runDeployStepListInteractive(array $arguments): array
{
    $command = new TestableDeployStepListCommand;
    $command->setLaravel(app());

    $output = new BufferedOutput;
    $exitCode = $command->run(new ArrayInput($arguments), $output);

    return [$exitCode, $output->fetch()];
}

it('fails with validation_failed when app is missing in --json mode', function (): void {
    Artisan::call('deploy:step-list', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});

it('prompts for app when missing in interactive mode', function (): void {
    makeProductionAppForList();

    TestableDeployStepListCommand::$searchResults = ['App' => 'docs'];

    [$exitCode] = runDeployStepListInteractive([]);

    expect($exitCode)->toBe(0);
});

it('does not prompt when app is provided in interactive mode', function (): void {
    makeProductionAppForList();

    [$exitCode] = runDeployStepListInteractive(['app' => 'docs']);

    expect($exitCode)->toBe(0);
});

it('cancels when app prompt is aborted in interactive mode', function (): void {
    makeProductionAppForList();

    TestableDeployStepListCommand::$abortPrompt = 'App';

    [$exitCode, $output] = runDeployStepListInteractive([]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Operation cancelled.');
});
