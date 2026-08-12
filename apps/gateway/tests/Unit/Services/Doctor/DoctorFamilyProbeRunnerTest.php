<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorIssue;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\Doctor\DoctorFamilyProbeRunner;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('reports family check progress and returns collected issues', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create(['name' => 'progress-node']);
    $events = [];
    $issue = doctor_family_probe_issue(
        node: $node,
        family: 'process',
        key: 'process.runtime_unit_missing',
    );

    $issues = app(DoctorFamilyProbeRunner::class)->run(
        node: $node,
        family: 'process',
        total: 2,
        key: null,
        onFamilyProgress: static function (...$arguments) use (&$events): void {
            $events[] = $arguments;
        },
        probe: static function (callable $addIssue, callable $advance) use ($issue): void {
            $addIssue($issue);
            $advance();
            $advance();
        },
    );

    expect($issues)
        ->toBe([$issue])
        ->and($events)
        ->toHaveCount(3)
        ->and($events[0])
        ->toMatchArray(['process', 'running', [], 0, 2])
        ->and($events[1])
        ->toMatchArray(['process', 'running', [], 1, 2])
        ->and($events[2][0] ?? null)
        ->toBe('process')
        ->and($events[2][1] ?? null)
        ->toBe('done')
        ->and($events[2][2][0]['key'] ?? null)
        ->toBe('process.runtime_unit_missing')
        ->and($events[2][3])
        ->toBeNull()
        ->and($events[2][4])
        ->toBeNull();
});

it('retains partial issues when remote shell access fails', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create(['name' => 'partial-node']);
    $partialIssue = doctor_family_probe_issue(
        node: $node,
        family: 'process',
        key: 'process.runtime_unit_missing',
    );
    $failure = new RemoteShellFailed(
        $node,
        'runtime probe',
        new RemoteShellResult(23, '', 'connection lost', 1),
    );

    $issues = app(DoctorFamilyProbeRunner::class)->run(
        node: $node,
        family: 'process',
        total: 1,
        key: null,
        onFamilyProgress: null,
        probe: static function (callable $addIssue) use ($partialIssue, $failure): void {
            $addIssue($partialIssue);

            throw $failure;
        },
    );

    expect(array_map(static fn (DoctorIssue $issue): string => $issue->key, $issues))
        ->toBe([
            'process.runtime_unit_missing',
            'process.remote_shell_probe_failed',
        ])
        ->and($issues[1]->detail['exit_code'] ?? null)
        ->toBe(23);
});

it('converts local executor transport failures without losing the family', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create(['name' => 'transport-node']);

    $issues = app(DoctorFamilyProbeRunner::class)->run(
        node: $node,
        family: 'tool',
        total: 0,
        key: null,
        onFamilyProgress: null,
        probe: static function (): void {
            throw new RemoteLocalExecutorTransportFailed('agent push unavailable');
        },
    );

    expect($issues)
        ->toHaveCount(1)
        ->and($issues[0]->key)
        ->toBe('tool.remote_shell_probe_failed')
        ->and($issues[0]->detail)
        ->toBe(['error' => 'agent push unavailable']);
});

it('does not duplicate a family-specific probe failure', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create(['name' => 'proxy-node']);
    $failureIssue = doctor_family_probe_issue(
        node: $node,
        family: 'proxy',
        key: 'proxy.node_probe_failed',
    );

    $issues = app(DoctorFamilyProbeRunner::class)->run(
        node: $node,
        family: 'proxy',
        total: 1,
        key: null,
        onFamilyProgress: null,
        probe: static function (callable $addIssue) use ($failureIssue, $node): void {
            $addIssue($failureIssue);

            throw new RemoteShellFailed(
                $node,
                'proxy probe',
                new RemoteShellResult(1, '', 'failed', 1),
            );
        },
    );

    expect($issues)->toBe([$failureIssue]);
});

it('filters only the done progress issues by the selected key', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create(['name' => 'key-node']);
    $events = [];
    $selected = doctor_family_probe_issue(
        node: $node,
        family: 'process',
        key: 'process.runtime_unit_missing',
    );
    $other = doctor_family_probe_issue(
        node: $node,
        family: 'process',
        key: 'process.runtime_unit_extra',
    );

    $issues = app(DoctorFamilyProbeRunner::class)->run(
        node: $node,
        family: 'process',
        total: 0,
        key: 'process.runtime_unit_missing',
        onFamilyProgress: static function (...$arguments) use (&$events): void {
            $events[] = $arguments;
        },
        probe: static function (callable $addIssue) use ($selected, $other): void {
            $addIssue($selected);
            $addIssue($other);
        },
    );

    expect($issues)
        ->toBe([$selected, $other])
        ->and($events)
        ->toHaveCount(2)
        ->and($events[0])
        ->toBe(['process', 'running', [], null, null])
        ->and(array_column($events[1][2], 'key'))
        ->toBe(['process.runtime_unit_missing']);
});

function doctor_family_probe_issue(Node $node, string $family, string $key): DoctorIssue
{
    return app(DoctorIssueFactory::class)->fromArray([
        'family' => $family,
        'node' => $node->name,
        'key' => $key,
        'kind' => DriftKind::Missing->value,
        'summary' => $key,
        'detail' => [],
    ]);
}
