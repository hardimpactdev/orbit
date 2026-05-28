<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes operator terminology for legacy control rows', function (): void {
    $node = Node::factory()->operator()->create([
        'name' => 'control-1', // Legacy persisted name stays valid during migration.
    ]);

    expect($node->isOperator())->toBeTrue()
        ->and($node->displayRole())->toBe('operator')
        ->and($node->role)->toBe('control');
});

it('keeps non-control roles unchanged in the display label', function (): void {
    $node = Node::factory()->create([

    ]);

    expect($node->isOperator())->toBeFalse()
        ->and($node->displayRole())->toBe('gateway');
});
