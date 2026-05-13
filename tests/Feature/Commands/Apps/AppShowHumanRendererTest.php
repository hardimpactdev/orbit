<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('app:show human renderer contract', function (): void {
    it('renders the registry detail view', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'host' => '10.6.0.7']);
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'environment' => 'development',
            'domain' => 'docs.test',
            'path' => '/srv/docs',
            'document_root' => 'public',
        ]);

        $exitCode = Artisan::call('app:show', ['app' => 'docs']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('App: docs')
            ->and($output)->toContain('Domain: docs.test')
            ->and($output)->toContain('Environment: development')
            ->and($output)->toContain('Node: app-1')
            ->and($output)->toContain('Registry:')
            ->and($output)->toContain('Document Root: /srv/docs/public')
            ->and($output)->toContain('Workspaces:')
            ->and($output)->toContain('(none)')
            ->and($output)->not->toContain('"success"');
    });

    it('renders not-found prose errors', function (): void {
        $exitCode = Artisan::call('app:show', ['app' => 'missing']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("App 'missing' not found or not visible.");
    });
});
