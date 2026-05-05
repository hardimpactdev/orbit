<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('workspace:history JSON renderer contract', function (): void {
    it('returns pagination metadata for empty history', function (): void {
        Node::factory()->create(['name' => 'local-gateway', 'role' => 'gateway', 'is_local' => true]);
        $app = App::factory()->create(['name' => 'docs']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $exitCode = Artisan::call('workspace:history', ['name' => 'feature-docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload['success']['data']['runs'])->toBe([])
            ->and($payload['success']['meta']['pagination']['limit'])->toBe(50);
    });

    it('returns limit validation in the documented JSON shape', function (): void {
        $exitCode = Artisan::call('workspace:history', ['name' => 'feature-docs', '--limit' => '-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'validation_failed',
                'message' => 'Invalid value for --limit: must be a positive integer.',
                'meta' => [
                    'field' => 'limit',
                    'value' => '-1',
                ],
            ]);
    });
});
