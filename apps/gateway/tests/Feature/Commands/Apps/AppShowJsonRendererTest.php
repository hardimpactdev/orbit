<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('app:show JSON renderer contract', function (): void {
    it('returns app and details under the success envelope', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1', 'host' => '10.6.0.7']);
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => 'docs.example.com',
            'path' => '/srv/docs',
            'document_root' => 'public',
            'repository' => 'git@github.com:orbit/docs.git',
            'php_version' => '8.5',
            'adopted' => false,
        ]);

        $exitCode = Artisan::call('app:show', ['app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload['success']['data']['app'])->toBe([
                'name' => 'docs',
                'node' => 'app-1',
                'url' => 'https://docs.example.com',
                'path' => '/srv/docs',
                'root' => 'public',
                'repository' => 'git@github.com:orbit/docs.git',
                'runtime_kind' => 'php',
                'php_version' => '8.5',
                'worker_enabled' => false,
                'worker_config' => null,
                'adopted' => false,
            ])
            ->and($payload['success']['data']['details']['workspaces'])->toBe([])
            ->and($payload['success']['data']['details']['processes'])->toBe([])
            ->and($payload['success']['data']['details']['routes'][0])->toBe([
                'host' => 'docs.example.com',
                'kind' => 'app',
                'owner' => 'app',
            ]);
    });
});
