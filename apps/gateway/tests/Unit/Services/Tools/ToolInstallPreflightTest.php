<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Tools\ToolInstallPreflight;
use App\Services\Tools\ToolRegistryFailure;
use Tests\TestCase;

uses(TestCase::class);

it('rejects a missing route TLD before any remote preflight probe', function (string $tool): void {
    $node = new Node([
        'name' => "{$tool}-without-tld",
        'status' => NodeStatus::Active,
        'platform' => 'ubuntu_24-04',
        'tld' => null,
    ]);

    $failure = app(ToolInstallPreflight::class)->check($tool, $node);

    expect($failure)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($failure->code)
        ->toBe('tool.constraint_unsatisfied')
        ->and($failure->meta)
        ->toMatchArray([
            'tool' => $tool,
            'action' => 'install',
            'constraint' => 'route_tld',
            'required' => 'configured',
            'actual' => null,
        ]);
})->with(['openclaw', 'hermes']);
