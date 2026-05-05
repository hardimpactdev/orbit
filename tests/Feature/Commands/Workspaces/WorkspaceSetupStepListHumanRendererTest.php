<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Node;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function createWorkspaceSetupStepListHumanApp(): App
{
    Node::factory()->create([
        'name' => 'local-gateway',
        'role' => 'gateway',
        'is_local' => true,
    ]);
    $node = Node::factory()->create(['role' => 'app']);

    return App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
}

describe('workspace-setup-step:list human renderer', function (): void {
    it('renders setup steps in a table', function (): void {
        $app = createWorkspaceSetupStepListHumanApp();
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 600,
        ]);

        $exitCode = Artisan::call('workspace-setup-step:list', ['--app' => 'docs']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Setup steps for docs:')
            ->and($output)->toContain('ID')
            ->and($output)->toContain('ORDER')
            ->and($output)->toContain('COMMAND')
            ->and($output)->toContain('TIMEOUT')
            ->and($output)->toContain('composer install')
            ->and($output)->toContain('600s');
    });

    it('renders the empty setup step message', function (): void {
        createWorkspaceSetupStepListHumanApp();

        $exitCode = Artisan::call('workspace-setup-step:list', ['--app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('No setup steps defined for docs.');
    });
});
