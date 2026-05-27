<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Services\Php\PhpRuntimeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('prompts for version in interactive mode when version is missing', function (): void {
    createPhpLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    createPhpTool($node, ['versions' => ['8.5', '8.4', '8.3']]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);

    $this->artisan('php:use', ['--app' => 'docs'])
        ->expectsChoice('PHP version', '8.5', PhpRuntimeCatalog::SUPPORTED)
        ->assertSuccessful();
});

it('does not prompt when version argument is supplied in interactive mode', function (): void {
    createPhpLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    createPhpTool($node, ['versions' => ['8.5', '8.4', '8.3']]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);

    $this->artisan('php:use', ['version' => '8.5', '--app' => 'docs'])
        ->doesntExpectOutput('PHP version')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when version is missing', function (): void {
    createPhpLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $exitCode = Artisan::call('php:use', ['--app' => 'docs', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('version');
});
