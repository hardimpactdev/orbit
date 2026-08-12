<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorTargetScope;
use App\Models\Node;
use App\Services\Doctor\DoctorDatabaseConnectionFamilyProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps one deterministic progress unit around database connection observation', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create(['name' => 'database-family-node', 'status' => 'active']);
    $events = [];

    $issues = app(DoctorDatabaseConnectionFamilyProbe::class)->probe(
        node: $node,
        scope: DoctorTargetScope::none(),
        key: null,
        onFamilyProgress: static function (
            string $family,
            string $phase,
            array $issues,
            ?int $completed,
            ?int $total,
        ) use (&$events): void {
            $events[] = compact('family', 'phase', 'issues', 'completed', 'total');
        },
    );

    expect($issues)->toBeEmpty();
    expect(collect($events)->pluck('family')->all())
        ->toBe(['database_connection', 'database_connection'])
        ->and(collect($events)->pluck('phase')->all())
        ->toBe(['running', 'done'])
        ->and(collect($events)->pluck('completed')->all())
        ->toBe([0, null])
        ->and(collect($events)->pluck('total')->all())
        ->toBe([1, null]);
});
