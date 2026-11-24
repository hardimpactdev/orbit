<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\InstanceAgentInstructions;
use App\Domain\Instances\NestedFleetRefresh;
use App\Domain\Projects\ProjectSourceReader;
use App\Models\Operation;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AgentInstructionsHelpers.php';

// The deployment of a container default whose Project has agent instructions, on its own database copy (AgentInstructionsHelpers).
// Every entry point admits through StartInstanceDeployAction: a manual API deploy (execute, or pinned for a commit), the automatic
// deploy (automatic) and the release's nested-fleet refresh (NestedFleetRefresh, pinned with `release`).
uses(RefreshDatabase::class);

beforeEach(fn () => instructionsDatabase($this));

afterEach(fn () => instructionsDatabaseClosed($this));

/** The deployed commit's source, a Laravel app on PHP 8.5 as the default's Project records. */
function instructedDeploySource(string $commit): void
{
    $file = static fn (string $content): array => ['mode' => '100644', 'object' => str_repeat('d', 40), 'content' => $content];
    test()->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(['head' => $commit, 'files' => ['.env.example' => $file("APP_NAME=tool\n"),
        'composer.json' => $file('{"require":{"php":"^8.5","laravel/framework":"^13.0"}}'), 'artisan' => $file('entry')]]);
}

it('deploys a container default whose Project has agent instructions, its seed holding them', function (string $entry): void {
    $default = instructedDefault();
    // The container intent stamps its bridge admission with the time.
    $this->freezeTime();
    instructedDocument($this, $default->project_id, 'agent/AGENTS.md', "Run the tests.\n");
    instructedDocument($this, $default->project_id, 'agent/.skills/release-notes/SKILL.md', "Write the notes.\n");
    instructedDeploySource(str_repeat('a', 40));

    $deploy = app(StartInstanceDeployAction::class);
    if ($entry === 'automatic') {
        app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
    }
    $operation = match ($entry) {
        'manual' => $deploy->execute($default, 'manual', null),
        'manual pin' => $deploy->pinned($default, 'manual', null, str_repeat('a', 40)),
        'automatic' => $deploy->automatic($default, $default->node, null, str_repeat('a', 40)),
        'nested-fleet refresh' => $deploy->pinned($default, NestedFleetRefresh::Trigger, null, str_repeat('a', 40), release: true),
    };

    expect(Operation::query()->where('kind', 'instance.deploy')->sole()->id)->toBe($operation->id);
    $container = $operation->input['deployment']['container'];
    $folder = collect($container['seed_files'])->firstWhere('folder.path', InstanceAgentInstructions::Folder)['folder'];
    expect(array_column($folder['files'], 'path'))->toBe(['AGENTS.md', '.skills/release-notes/SKILL.md'])
        ->and($folder['files'][0]['content'])->toEndWith(InstanceAgentInstructions::ProjectHeading."\n\nRun the tests.\n")
        ->and($folder['files'][1]['content'])->toBe("Write the notes.\n")
        // The same container as one built outside a transaction.
        ->and($container)->toBe(InstanceDeploymentIntent::container($default->refresh(), $default->runtime()->firstOrFail()));
})->with(['manual', 'manual pin', 'automatic', 'nested-fleet refresh']);

it('deploys a default without agent instructions with the container it had before', function (): void {
    $default = instructedDefault();
    // The container intent stamps its bridge admission with the time.
    $this->freezeTime();
    instructedDeploySource(str_repeat('a', 40));

    $operation = app(StartInstanceDeployAction::class)->execute($default, 'manual', null);

    $container = $operation->input['deployment']['container'];
    expect($container)->toBe(InstanceDeploymentIntent::container($default->refresh(), $default->runtime()->firstOrFail()))
        ->and(collect($container['seed_files'])->firstWhere('folder.path', InstanceAgentInstructions::Folder)['folder']['files'])->toHaveCount(1);
});
