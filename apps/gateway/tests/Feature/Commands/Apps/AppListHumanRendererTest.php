<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('app:list human renderer contract', function (): void {
    it('renders grouped app tables by owning node', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);

        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'environment' => 'development', 'domain' => 'docs.test']);
        App::factory()->create(['name' => 'site', 'node_id' => $node->id, 'environment' => 'production', 'domain' => 'site.test']);

        $exitCode = Artisan::call('app:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Node: app-1')
            ->and($output)->toContain('docs')
            ->and($output)->toContain('site')
            ->and($output)->not->toContain('ENVIRONMENT')
            ->and($output)->toContain('URL')
            ->and($output)->toContain('STATUS')
            ->and($output)->not->toContain('"success"');
    });

    it('renders workspace child rows below their parent app', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'tld' => 'test']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'environment' => 'development', 'domain' => null]);

        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $exitCode = Artisan::call('app:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('docs')
            ->and($output)->toContain('└─ feature-docs')
            ->and($output)->toContain('https://feature-docs.docs.test')
            ->and($output)->toContain('expected');
    });

    it('renders empty result prose', function (): void {
        $exitCode = Artisan::call('app:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('No apps found.');
    });

    it('renders validation failures as prose', function (): void {
        $exitCode = Artisan::call('app:list', ['--environment' => 'staging']);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("Invalid value for --environment: 'staging'. Allowed values: development, production.");
    });
});
