<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);

    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
});

it('prompts for missing app in interactive mode when cwd cannot resolve it', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'tld' => 'test',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'document_root' => 'public',
        'environment' => 'development',
    ]);

    $this->artisan('app:show')
        ->expectsQuestion('App name or hostname', 'docs')
        ->expectsOutputToContain('docs')
        ->assertSuccessful();
});

it('does not prompt when app is supplied', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'tld' => 'test',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'document_root' => 'public',
        'environment' => 'development',
    ]);

    $exitCode = Artisan::call('app:show', ['app' => 'docs', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['app']['name'])->toBe('docs');
});

it('fails non-interactively when app is missing and --json is set', function (): void {
    $exitCode = Artisan::call('app:show', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});
