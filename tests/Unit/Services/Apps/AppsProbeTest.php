<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Apps;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new AppsProbe;
});

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('apps');
        expect($this->probe->label())->toBe('Apps');
    });

    it('returns empty snapshot from introspect', function (): void {
        $app = new App(['name' => 'site']);
        $snapshot = $this->probe->introspect($app);

        expect($snapshot->isEmpty())->toBeTrue();
    });
});

describe('registry intent', function (): void {
    it('passes complete app records on active app nodes', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete app records', function (): void {
        $node = appNode();

        $id = DB::table('apps')->insertGetId([
            'name' => 'incomplete',
            'node_id' => $node->id,
            'environment' => '',
            'path' => '',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $app = App::findOrFail($id);

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->family)->toBe('apps');
        expect($drift[0]->key)->toBe('app.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });
});

describe('owning node eligibility', function (): void {
    it('requires an active app node owner', function (array $nodeState): void {
        $node = Node::factory()->create($nodeState);
        $app = App::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $ownerIssues = array_values(array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.owner_node_invalid',
        ));

        expect($ownerIssues)->toHaveCount(1);
        expect($ownerIssues[0]->kind)->toBe(DriftKind::Divergent);
    })->with([
        'gateway owner' => [['role' => 'gateway', 'status' => 'active']],
        'inactive app owner' => [['role' => 'app', 'status' => 'inactive']],
    ]);

    it('accepts active app node owners', function (): void {
        $node = appNode();
        $app = App::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $ownerIssues = array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.owner_node_invalid',
        );

        expect($ownerIssues)->toHaveCount(0);
    });
});

describe('app agent IDE defaults', function (): void {
    it('detects unsupported app agent IDE adapters', function (): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create(['agent_ide_config' => ['adapter' => 'unsupported']]);

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $adapterIssues = array_values(array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.agent_ide_default_invalid',
        ));

        expect($adapterIssues)->toHaveCount(1);
        expect($adapterIssues[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('accepts supported app agent IDE adapters', function (?array $agentIdeConfig): void {
        $node = appNode();
        $app = App::factory()
            ->for($node, 'node')
            ->create(['agent_ide_config' => $agentIdeConfig]);

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $adapterIssues = array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.agent_ide_default_invalid',
        );

        expect($adapterIssues)->toHaveCount(0);
    })->with([
        'inherited default' => [null],
        'disabled' => [['adapter' => 'none']],
        'opencode' => [['adapter' => 'opencode']],
        'polyscope' => [['adapter' => 'polyscope']],
    ]);
});

function appNode(array $overrides = []): Node
{
    return Node::factory()->create([
        'role' => 'app',
        'status' => 'active',
        'environment' => 'development',
        ...$overrides,
    ]);
}
