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

describe('workspace:show JSON renderer contract', function (): void {
    it('selects JSON renderer and returns registry-only metadata', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => null]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $exitCode = Artisan::call('workspace:show', ['name' => 'feature-docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta']['registry_only'])->toBeTrue()
            ->and($payload['success']['data']['workspace']['name'])->toBe('feature-docs')
            ->and($payload['success']['data']['workspace']['runtime_expectations']['hostname'])->toBe('feature-docs.docs.test');
    });

    it('returns not found in the documented JSON shape', function (): void {
        $exitCode = Artisan::call('workspace:show', ['name' => 'missing', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'workspace.not_found',
                'message' => "Workspace 'missing' not found or not visible.",
                'meta' => [
                    'name' => 'missing',
                ],
            ]);
    });
});
