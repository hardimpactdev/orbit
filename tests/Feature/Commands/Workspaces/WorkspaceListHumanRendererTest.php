<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('workspace:list human renderer contract', function (): void {
    it('renders grouped workspace tables by owning node and app', function (): void {
        Node::factory()->create(['name' => 'local-gateway', 'role' => 'gateway', 'is_local' => true]);
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.test']);

        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Workspace::factory()->create(['name' => 'main', 'app_id' => $app->id]);

        $exitCode = Artisan::call('workspace:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Node: app-1')
            ->and($output)->toContain('App: docs')
            ->and($output)->toContain('feature-docs')
            ->and($output)->toContain('main')
            ->and($output)->toContain('WORKSPACE')
            ->and($output)->toContain('URL')
            ->and($output)->toContain('LIFECYCLE STATUS')
            ->and($output)->not->toContain('"success"');
    });

    it('renders empty result prose', function (): void {
        Node::factory()->create(['name' => 'local-gateway', 'role' => 'gateway', 'is_local' => true]);

        $exitCode = Artisan::call('workspace:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('No workspaces found.');
    });

    it('renders validation failures as prose', function (): void {
        $exitCode = Artisan::call('workspace:list', ['--app' => 'docs,site']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("Unknown app: 'docs,site'.");
    });
});
