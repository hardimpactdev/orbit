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
        Node::factory()->create(['name' => 'local-gateway', 'role' => 'gateway', 'is_local' => true]);
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
                'environment' => 'development',
                'url' => 'https://docs.test',
                'path' => '/srv/docs',
                'root' => 'public',
                'repository' => null,
                'php_version' => '8.5',
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

    it('returns an empty apps array when no apps match', function (): void {
        Node::factory()->create(['name' => 'local-gateway', 'role' => 'gateway', 'is_local' => true]);

        $exitCode = Artisan::call('app:list', ['--json' => true, '--environment' => 'production']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['apps'])->toBe([]);
    });
});
