<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

describe('workspace:list JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns the workspace list entity shape', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'tld' => 'test']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => null]);

        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        $exitCode = Artisan::call('workspace:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['data']['workspaces'][0])->toBe([
                'name' => 'feature-docs',
                'app' => 'docs',
                'node' => 'app-1',
                'url' => 'https://feature-docs.docs.test',
                'lifecycle_status' => 'setup-pending',
            ]);
    });

    it('returns an empty workspaces array when no workspaces match', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $exitCode = Artisan::call('workspace:list', ['--json' => true, '--app' => 'docs']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['workspaces'])->toBe([]);
    });

    it('returns validation errors in the documented JSON shape', function (): void {
        $exitCode = Artisan::call('workspace:list', ['--json' => true, '--node' => 'missing-node']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'validation_failed',
                'message' => "Unknown node: 'missing-node'.",
                'meta' => [
                    'field' => 'node',
                    'value' => 'missing-node',
                ],
            ]);
    });
});
