<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('workspace:history human renderer contract', function (): void {
    it('renders a history table without a progress tree', function (): void {
        Node::factory()->create(['name' => 'local-gateway', 'role' => 'gateway', 'is_local' => true]);
        $app = App::factory()->create(['name' => 'docs']);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        WorkspaceRun::factory()->create(['workspace_id' => $workspace->id, 'status' => 'completed']);

        $exitCode = Artisan::call('workspace:history', ['name' => 'feature-docs', '--app' => 'docs']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Workspace History: docs/feature-docs')
            ->and($output)->toContain('Action')
            ->and($output)->toContain('Status')
            ->and($output)->not->toContain('"success"')
            ->and($output)->not->toContain('○');
    });

    it('renders empty history prose', function (): void {
        Node::factory()->create(['name' => 'local-gateway', 'role' => 'gateway', 'is_local' => true]);
        $app = App::factory()->create(['name' => 'docs']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $exitCode = Artisan::call('workspace:history', ['name' => 'feature-docs', '--app' => 'docs']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('No workspace history found.');
    });
});
