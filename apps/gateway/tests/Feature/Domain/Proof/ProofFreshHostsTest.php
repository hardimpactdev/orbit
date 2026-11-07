<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApiException;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofFreshHosts;
use App\Domain\TaskVms\TaskVmFleetSeed;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Domain\TaskVms\TaskVmSeedFloor;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

const FRESH_BASE = 'b000000000000000000000000000000000000001';
const FRESH_NEW = 'c000000000000000000000000000000000000002';
const FRESH_OLD = 'a000000000000000000000000000000000000003';

/** A host whose latest refresh saved copies of the current format, built from `$commit` (null: the refresh recorded none). */
function freshHost(string $name, ?string $commit, int $build = 1): Node
{
    $host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.'.random_int(20, 200), 'wireguard_ip' => '10.44.0.'.random_int(20, 200), 'user' => 'orbit']));
    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $host->id, 'status' => OperationStatus::Succeeded,
        'input' => ['refresh' => ['host_node_id' => $host->id, ...($commit === null ? [] : ['commit' => $commit]), 'ready_copies' => ['fleet' => ['format' => TaskVmReadyCopiesIntent::Format]]]],
        'result' => ['refreshed' => true, 'host_node_id' => $host->id, 'build_id' => $build], 'triggered_by' => 'test']);

    return $host;
}

/** The hosts as the settings list them. */
function freshSettings(Node ...$hosts): void
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        array_map(static fn (Node $host): TaskVmHost => new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB'), $hosts),
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
}

/** A GitHub that knows which release commits are at or after the merge base. */
function freshGitHub(array $atOrAfter, ?array &$asked = null): void
{
    $asked = [];
    app()->instance(ProofCommitSource::class, Mockery::mock(ProofCommitSource::class, function ($source) use ($atOrAfter, &$asked): void {
        $source->shouldReceive('atOrAfter')->andReturnUsing(function (string $base, string $sha) use ($atOrAfter, &$asked): bool {
            $asked[] = [$base, $sha];

            return $atOrAfter[$sha] ?? false;
        });
    }));
}

it('names the hosts whose copies come from a release at or after the merge base, and only those', function (): void {
    $beast = freshHost('beast', FRESH_OLD);
    $shark = freshHost('shark', FRESH_NEW);
    freshSettings($beast, $shark);
    freshGitHub([FRESH_NEW => true, FRESH_OLD => false], $asked);

    expect(app(ProofFreshHosts::class)->hosts(FRESH_BASE))->toBe([$shark->id])->and($asked)->toBe([[FRESH_BASE, FRESH_OLD], [FRESH_BASE, FRESH_NEW]]);
});

it('asks GitHub once for each release commit', function (): void {
    $hosts = [freshHost('beast', FRESH_NEW), freshHost('shark', FRESH_NEW)];
    freshSettings(...$hosts);
    freshGitHub([FRESH_NEW => true], $asked);

    expect(app(ProofFreshHosts::class)->hosts(FRESH_BASE))->toBe([$hosts[0]->id, $hosts[1]->id])->and($asked)->toHaveCount(1);
});

it('leaves out a host whose copies record no release commit, without asking GitHub', function (): void {
    $beast = freshHost('beast', null);
    freshSettings($beast);
    freshGitHub([], $asked);

    expect(app(ProofFreshHosts::class)->hosts(FRESH_BASE))->toBe([])->and($asked)->toBe([]);
});

it('leaves out a host whose release commit GitHub does not know', function (): void {
    $beast = freshHost('beast', FRESH_NEW);
    freshSettings($beast);
    freshGitHub([FRESH_NEW => false]);

    expect(app(ProofFreshHosts::class)->hosts(FRESH_BASE))->toBe([]);
});

it('lets the GitHub failure through, so a proof never guesses the age of the copies', function (): void {
    $beast = freshHost('beast', FRESH_NEW);
    freshSettings($beast);
    app()->instance(ProofCommitSource::class, Mockery::mock(ProofCommitSource::class, function ($source): void {
        $source->shouldReceive('atOrAfter')->andThrow(GitHubApiException::unavailable());
    }));

    expect(fn () => app(ProofFreshHosts::class)->hosts(FRESH_BASE))->toThrow(GitHubApiException::class);
});

it('keeps the seed of a host usable for any task, and for a proof only while the floor allows the host', function (): void {
    $beast = freshHost('beast', FRESH_OLD);
    $shark = freshHost('shark', FRESH_NEW, build: 2);
    $seeds = app(TaskVmFleetSeed::class);
    $floor = app(TaskVmSeedFloor::class);

    expect($seeds->usable($beast->id, 1))->toBeTrue()->and($seeds->usable($shark->id, 2))->toBeTrue();
    $floor->during([$shark->id], function () use ($seeds, $beast, $shark, $floor): void {
        expect($floor->active())->toBeTrue()->and($seeds->usable($beast->id, 1))->toBeFalse()->and($seeds->usable($shark->id, 2))->toBeTrue()
            // The build must still be the one the host serves.
            ->and($seeds->usable($shark->id, 99))->toBeFalse();
    });
    expect($floor->active())->toBeFalse()->and($seeds->usable($beast->id, 1))->toBeTrue();
});

it('restores the floor of the outer admission when a nested one ends, also after an exception', function (): void {
    $floor = app(TaskVmSeedFloor::class);

    try {
        $floor->during([1], function () use ($floor): void {
            $floor->during([2], static fn () => throw new RuntimeException('the admission failed'));
        });
    } catch (RuntimeException) {
    }

    expect($floor->active())->toBeFalse();
    $floor->during([1], function () use ($floor): void {
        try {
            $floor->during([], static fn () => throw new RuntimeException('again'));
        } catch (RuntimeException) {
        }
        expect($floor->allows(1))->toBeTrue()->and($floor->allows(2))->toBeFalse();
    });
});

it('reads the release commit that the latest refresh saved, not an older refresh', function (): void {
    $beast = freshHost('beast', FRESH_OLD);
    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_group', 'target_id' => 2, 'node_id' => $beast->id, 'status' => OperationStatus::Succeeded,
        'input' => ['refresh' => ['host_node_id' => $beast->id, 'commit' => FRESH_NEW, 'ready_copies' => ['fleet' => ['format' => TaskVmReadyCopiesIntent::Format]]]],
        'result' => ['refreshed' => true, 'host_node_id' => $beast->id, 'build_id' => 1], 'triggered_by' => 'test']);
    // A later refresh that kept the seed (seed=keep) saved no copies and does not count.
    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_group', 'target_id' => 3, 'node_id' => $beast->id, 'status' => OperationStatus::Succeeded,
        'input' => ['refresh' => ['host_node_id' => $beast->id, 'commit' => FRESH_BASE, 'seed' => 'keep', 'ready_copies' => ['fleet' => ['format' => TaskVmReadyCopiesIntent::Format]]]],
        'result' => ['refreshed' => true, 'host_node_id' => $beast->id, 'build_id' => 1], 'triggered_by' => 'test']);

    expect(app(TaskVmFleetSeed::class)->commit($beast->id))->toBe(FRESH_NEW);
});
