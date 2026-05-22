<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('app:list JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns the canonical app entity shape', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'environment' => 'development',
            'domain' => null,
            'path' => '/srv/docs',
            'document_root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'adopted' => false,
        ]);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
        ]);

        $exitCode = Artisan::call('app:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['data']['apps'][0])->toBe([
                'name' => 'docs',
                'node' => 'app-1',
                'url' => 'https://docs.test',
                'path' => '/srv/docs',
                'root' => 'public',
                'repository' => null,
                'runtime_kind' => 'php',
                'php_version' => '8.5',
                'worker_enabled' => false,
                'worker_config' => null,
                'adopted' => false,
                'workspaces' => [
                    [
                        'name' => 'feature-docs',
                        'url' => 'https://feature-docs.docs.test',
                        'lifecycle_status' => 'expected',
                    ],
                ],
            ]);
    });

    it('exposes runtime_kind=static for static apps', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);
        App::factory()->static()->create([
            'name' => 'marketing',
            'node_id' => $node->id,
            'environment' => 'development',
            'domain' => null,
            'path' => '/srv/marketing',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
        ]);

        $exitCode = Artisan::call('app:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['apps'][0]['name'])->toBe('marketing')
            ->and($payload['success']['data']['apps'][0]['runtime_kind'])->toBe('static');
    });

    it('returns an empty apps array when no apps match', function (): void {
        $exitCode = Artisan::call('app:list', ['--json' => true, '--environment' => 'production']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['apps'])->toBe([]);
    });
});
