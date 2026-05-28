<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Node;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

function createWorkspaceTeardownStepListHumanApp(): App
{
    Node::factory()->create([
        'name' => 'local-gateway',

    ]);
    $node = Node::factory()->create([]);

    return App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
}

describe('workspace-teardown-step:list human renderer', function (): void {
    it('renders teardown steps in a table', function (): void {
        $app = createWorkspaceTeardownStepListHumanApp();
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
            'sort_order' => 1,
            'command' => 'dropdb docs',
            'timeout_seconds' => 600,
        ]);

        $exitCode = Artisan::call('workspace-teardown-step:list', ['--app' => 'docs']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Teardown steps for docs:')
            ->and($output)->toContain('dropdb docs')
            ->and($output)->toContain('600s');
    });

    it('renders the empty teardown step message', function (): void {
        createWorkspaceTeardownStepListHumanApp();

        $exitCode = Artisan::call('workspace-teardown-step:list', ['--app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('No teardown steps defined for docs.');
    });
});
