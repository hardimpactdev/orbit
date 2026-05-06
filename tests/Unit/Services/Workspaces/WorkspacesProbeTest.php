<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workspaces;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacesProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new WorkspacesProbe;
});

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('workspaces');
        expect($this->probe->label())->toBe('Workspaces');
    });

    it('returns empty snapshot from introspect', function (): void {
        $workspace = new Workspace(['name' => 'feature']);
        $snapshot = $this->probe->introspect($workspace);

        expect($snapshot->isEmpty())->toBeTrue();
    });
});

describe('registry intent', function (): void {
    it('passes complete workspace records with eligible parent apps', function (): void {
        $app = workspaceableApp();
        $workspace = Workspace::factory()->for($app, 'app')->create();

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete workspace records', function (): void {
        $app = workspaceableApp();

        $id = DB::table('workspaces')->insertGetId([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '',
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workspace = Workspace::findOrFail($id);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->family)->toBe('workspaces');
        expect($drift[0]->key)->toBe('workspace.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });

    it('accepts PHP version inherited from the parent app', function (): void {
        $app = workspaceableApp(['php_version' => '8.5']);
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create(['php_version' => null]);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));
        $recordIssues = array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'workspace.record_incomplete',
        );

        expect($recordIssues)->toHaveCount(0);
    });

    it('requires an effective PHP version', function (): void {
        $app = workspaceableApp(['php_version' => '']);
        $workspace = Workspace::factory()
            ->for($app, 'app')
            ->create(['php_version' => null]);

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });
});

describe('parent app eligibility', function (): void {
    it('requires a parent app on an active app node', function (array $nodeState): void {
        $node = Node::factory()->create($nodeState);
        $app = App::factory()->for($node, 'node')->create();
        $workspace = Workspace::factory()->for($app, 'app')->create();

        $drift = $this->probe->diff($workspace, new ProbeSnapshot([]));

        expect(issue($drift, 'workspace.parent_app_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'gateway parent node' => [['role' => 'gateway', 'status' => 'active']],
        'inactive app parent node' => [['role' => 'app', 'status' => 'inactive']],
    ]);
});

function issue(array $drift, string $key): ?DriftEntry
{
    return collect($drift)->first(fn (DriftEntry $entry): bool => $entry->key === $key);
}

function workspaceableApp(array $overrides = []): App
{
    $node = Node::factory()->create([
        'role' => 'app',
        'status' => 'active',
        'environment' => 'development',
    ]);

    return App::factory()
        ->for($node, 'node')
        ->create($overrides);
}
