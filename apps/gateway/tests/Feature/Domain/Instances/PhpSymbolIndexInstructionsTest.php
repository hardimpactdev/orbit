<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\InstanceAgentInstructions;
use App\Domain\Instances\PhpSymbolIndexRefresh;
use App\Models\Operation;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AgentInstructionsHelpers.php';

// The index refresh of a default whose Project has agent instructions, on its own database copy (AgentInstructionsHelpers).
uses(RefreshDatabase::class);

beforeEach(fn () => instructionsDatabase($this));

afterEach(fn () => instructionsDatabaseClosed($this));

it('admits the index refresh of a default whose Project has agent instructions, its seed holding them', function (): void {
    $default = instructedDefault();
    // The container intent stamps its bridge admission with the time.
    $this->freezeTime();
    instructedDocument($this, $default->project_id, 'agent/AGENTS.md', "Run the tests.\n");
    instructedDocument($this, $default->project_id, 'agent/.skills/release-notes/SKILL.md', "Write the notes.\n");

    $this->artisan('instances:php-index', ['instance' => [(string) $default->id]])
        ->expectsOutputToContain('"instance":'.$default->id.',"operation":')->assertExitCode(0);

    $operation = Operation::query()->where('kind', PhpSymbolIndexRefresh::Kind)->sole();
    $folder = collect($operation->input['container']['seed_files'])->firstWhere('folder.path', InstanceAgentInstructions::Folder)['folder'];
    expect(array_column($folder['files'], 'path'))->toBe(['AGENTS.md', '.skills/release-notes/SKILL.md'])
        ->and($folder['files'][0]['content'])->toEndWith(InstanceAgentInstructions::ProjectHeading."\n\nRun the tests.\n")
        ->and($folder['files'][1]['content'])->toBe("Write the notes.\n")
        // The same container as the default's deployment would freeze, read outside a transaction.
        ->and($operation->input['container'])->toBe(InstanceDeploymentIntent::container($default->refresh(), $default->runtime()->firstOrFail()));
});

it('admits a default without agent instructions with the input it had before', function (): void {
    $default = instructedDefault();
    // The container intent stamps its bridge admission with the time.
    $this->freezeTime();

    $operation = app(PhpSymbolIndexRefresh::class)->start($default, null, null);

    expect($operation->input)->toBe(['container' => InstanceDeploymentIntent::container($default->refresh(), $default->runtime()->firstOrFail()),
        'seed_commit' => str_repeat('c', 40)])
        ->and(collect($operation->input['container']['seed_files'])->firstWhere('folder.path', InstanceAgentInstructions::Folder)['folder']['files'])
        ->toHaveCount(1);
});
