<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofCommitRange;
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

/**
 * How the merge base stands to a release commit, as GitHub says: `$status` and the files that differ.
 *
 * @param  list<string>  $paths
 */
function freshRange(string $status, array $paths = [], bool $complete = true): ProofCommitRange
{
    return new ProofCommitRange(GitHubComparisonStatus::from($status), new ProofChangedFiles(FRESH_BASE, array_map(static fn (string $path): ProofChangedFile => new ProofChangedFile($path, 'modified'), $paths), $complete));
}

/**
 * A GitHub that answers each release commit with a range; a commit it lacks is unknown.
 *
 * @param  array<string,ProofCommitRange|null>  $ranges
 * @param  list<array{string,string}>|null  $asked  every question: from, to
 */
function freshGitHub(array $ranges, ?array &$asked = null): void
{
    $asked = [];
    app()->instance(ProofCommitSource::class, Mockery::mock(ProofCommitSource::class, function ($source) use ($ranges, &$asked): void {
        $source->shouldReceive('range')->andReturnUsing(function (string $from, string $to) use ($ranges, &$asked): ?ProofCommitRange {
            $asked[] = [$from, $to];

            return $ranges[$from] ?? null;
        });
    }));
}

it('asks how the merge base stands to the copies, and names a host fresh when main moved by documents or Gateway PHP only', function (): void {
    $beast = freshHost('beast', FRESH_OLD);
    freshSettings($beast);
    freshGitHub([FRESH_OLD => freshRange('ahead', ['docs/reference/pipeline/3-proof.mmd', 'apps/gateway/app/Domain/Proof/ProofRunIntent.php', 'apps/cli/app/Commands/Proof/RunProofCommand.php', 'swamp/proof/firewall/allow.cases.yaml'])], $asked);

    expect(app(ProofFreshHosts::class)->assess(FRESH_BASE)->hosts)->toBe([$beast->id])->and($asked)->toBe([[FRESH_OLD, FRESH_BASE]]);
});

it('names a host stale when main moved by a change of what the image carries', function (string $path): void {
    $beast = freshHost('beast', FRESH_OLD);
    freshSettings($beast);
    freshGitHub([FRESH_OLD => freshRange('ahead', ['docs/a.md', $path])]);

    expect(app(ProofFreshHosts::class)->assess(FRESH_BASE)->hosts)->toBe([]);
})->with([
    'a playbook' => 'swamp/ansible/tasks/task-vm-software.yml',
    'a pin' => 'apps/gateway/resources/machine-software/recipe.json',
    'the root lock file' => 'composer.lock',
    'the lock file of an app' => 'apps/gateway/composer.lock',
    'the bun lock file of an app' => 'apps/web/bun.lock',
]);

it('counts the old path of a rename out of what the image carries', function (): void {
    $beast = freshHost('beast', FRESH_OLD);
    freshSettings($beast);
    app()->instance(ProofCommitSource::class, Mockery::mock(ProofCommitSource::class, function ($source): void {
        $source->shouldReceive('range')->andReturn(new ProofCommitRange(GitHubComparisonStatus::Ahead,
            new ProofChangedFiles(FRESH_BASE, [new ProofChangedFile('docs/pins.json', 'renamed', 'apps/gateway/resources/machine-software/pins.json')], true)));
    }));

    expect(app(ProofFreshHosts::class)->assess(FRESH_BASE)->hosts)->toBe([]);
});

it('says why each stale host is stale: the release its copies come from and the change they lack', function (): void {
    $stale = freshHost('beast', FRESH_OLD);
    $none = freshHost('shark', null);
    $unknown = freshHost('sabre', FRESH_NEW);
    freshSettings($stale, $none, $unknown);
    freshGitHub([FRESH_OLD => freshRange('ahead', ['docs/a.md', 'swamp/ansible/tasks/task-vm-software.yml', 'composer.lock']), FRESH_NEW => null]);

    $freshness = app(ProofFreshHosts::class)->assess(FRESH_BASE);

    expect($freshness->hosts)->toBe([])->and($freshness->reasons)->toBe([
        $stale->id => 'its ready copies come from release a00000000000 and lack a change of swamp/ansible/tasks/task-vm-software.yml and 1 more',
        $none->id => 'its ready copies record no release',
        $unknown->id => 'its ready copies come from release c00000000000, which GitHub does not know',
    ]);
});

it('says that a diverged release is not on the history of the merge base, and that a cut list cannot be checked', function (): void {
    $diverged = freshHost('beast', FRESH_OLD);
    $cut = freshHost('shark', FRESH_NEW);
    freshSettings($diverged, $cut);
    freshGitHub([FRESH_OLD => freshRange('diverged'), FRESH_NEW => freshRange('ahead', ['docs/a.md'], complete: false)]);

    expect(app(ProofFreshHosts::class)->assess(FRESH_BASE)->reasons)->toBe([
        $diverged->id => 'its ready copies come from release a00000000000, which is not on the history of the merge base',
        $cut->id => 'its ready copies come from release c00000000000, and too many files changed since to check them',
    ]);
});
it('treats copies that are the merge base, or after it, as fresh', function (string $status): void {
    $beast = freshHost('beast', FRESH_NEW);
    freshSettings($beast);
    freshGitHub([FRESH_NEW => freshRange($status)]);

    expect(app(ProofFreshHosts::class)->assess(FRESH_BASE)->hosts)->toBe([$beast->id]);
})->with(['identical', 'behind']);

it('treats copies that diverged from the merge base, a cut list of files and an unknown release as stale', function (?ProofCommitRange $range): void {
    $beast = freshHost('beast', FRESH_NEW);
    freshSettings($beast);
    freshGitHub([FRESH_NEW => $range]);

    expect(app(ProofFreshHosts::class)->assess(FRESH_BASE)->hosts)->toBe([]);
})->with([
    'diverged' => fn () => freshRange('diverged'),
    'a list that GitHub cut' => fn () => freshRange('ahead', ['docs/a.md'], complete: false),
    'a release GitHub does not know' => null,
]);

it('leaves out a host whose copies record no release commit, without asking GitHub, so the proof waits', function (): void {
    $beast = freshHost('beast', null);
    freshSettings($beast);
    freshGitHub([], $asked);

    expect(app(ProofFreshHosts::class)->assess(FRESH_BASE)->hosts)->toBe([])->and($asked)->toBe([]);
});

it('moves to the fresh hosts when one host is stale, and asks once for each release commit', function (): void {
    $beast = freshHost('beast', FRESH_OLD);
    $shark = freshHost('shark', FRESH_NEW);
    $second = freshHost('sabre', FRESH_NEW);
    freshSettings($beast, $shark, $second);
    freshGitHub([FRESH_OLD => freshRange('ahead', ['swamp/ansible/x.yml']), FRESH_NEW => freshRange('identical')], $asked);

    expect(app(ProofFreshHosts::class)->assess(FRESH_BASE)->hosts)->toBe([$shark->id, $second->id])->and($asked)->toHaveCount(2);
});

it('lets the GitHub failure through, so a proof never guesses the age of the copies', function (): void {
    $beast = freshHost('beast', FRESH_NEW);
    freshSettings($beast);
    app()->instance(ProofCommitSource::class, Mockery::mock(ProofCommitSource::class, function ($source): void {
        $source->shouldReceive('range')->andThrow(GitHubApiException::unavailable());
    }));

    expect(fn () => app(ProofFreshHosts::class)->assess(FRESH_BASE)->hosts)->toThrow(GitHubApiException::class);
});

it('keeps the seed of a host usable for any task, and for a proof only while the floor allows the host', function (): void {
    $beast = freshHost('beast', FRESH_OLD);
    $shark = freshHost('shark', FRESH_NEW, build: 2);
    $seeds = app(TaskVmFleetSeed::class);
    $floor = app(TaskVmSeedFloor::class);

    expect($seeds->usable($beast->id, 1))->toBeTrue()->and($seeds->usable($shark->id, 2))->toBeTrue();
    $floor->during([$shark->id], [], function () use ($seeds, $beast, $shark, $floor): void {
        expect($floor->active())->toBeTrue()->and($seeds->usable($beast->id, 1))->toBeFalse()->and($seeds->usable($shark->id, 2))->toBeTrue()
            // The build must still be the one the host serves.
            ->and($seeds->usable($shark->id, 99))->toBeFalse();
    });
    expect($floor->active())->toBeFalse()->and($seeds->usable($beast->id, 1))->toBeTrue();
});

it('restores the floor of the outer admission when a nested one ends, also after an exception', function (): void {
    $floor = app(TaskVmSeedFloor::class);

    try {
        $floor->during([1], [], function () use ($floor): void {
            $floor->during([2], [], static fn () => throw new RuntimeException('the admission failed'));
        });
    } catch (RuntimeException) {
    }

    expect($floor->active())->toBeFalse();
    $floor->during([1], [], function () use ($floor): void {
        try {
            $floor->during([], [], static fn () => throw new RuntimeException('again'));
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
