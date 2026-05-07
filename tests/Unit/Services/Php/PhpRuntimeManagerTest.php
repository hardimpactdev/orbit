<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Php;

use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('maps PHP runtime view with inherited workspace version', function (): void {
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php', 'config' => ['versions' => ['8.5'], 'cli_version' => '8.5']]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.5']);
    Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => null]);

    $result = app(PhpRuntimeManager::class)->view(app: 'docs', workspace: 'feature-docs');

    expect($result->failed())->toBeFalse()
        ->and($result->payload['workspace'])->toBe([
            'name' => 'feature-docs',
            'php_version' => '8.5',
            'inherits' => true,
        ]);
});
