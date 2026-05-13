<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

describe('workspace:show human renderer contract', function (): void {
    it('renders registry detail sections without a progress tree', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'host' => '1.2.3.4']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.test']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $exitCode = Artisan::call('workspace:show', ['name' => 'feature-docs', '--app' => 'docs']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Workspace: feature-docs')
            ->and($output)->toContain('App:       docs')
            ->and($output)->toContain('Agent IDE:')
            ->and($output)->toContain('Runtime Expectations:')
            ->and($output)->toContain('Inherited Processes:')
            ->and($output)->toContain('Latest Setup Run:')
            ->and($output)->toContain('doctor --family=workspace')
            ->and($output)->not->toContain('"success"')
            ->and($output)->not->toContain('○');
    });

    it('renders missing input as prose', function (): void {
        $exitCode = Artisan::call('workspace:show', ['--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Workspace name is required.');
    });
});
