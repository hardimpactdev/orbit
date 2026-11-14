<?php

declare(strict_types=1);

use App\Domain\Instances\InstancePlacement;
use Symfony\Component\Process\Process as LocalProcess;

/*
 * The production reader (resources/instances/workspace-git-read.py) against real Git repositories, with a local Incus
 * stand-in that fails closed on any argv that is not fixed text or a hex object id. No container or Node is contacted.
 */

beforeEach(function (): void {
    $this->folder = sys_get_temp_dir().'/workspace-read-'.bin2hex(random_bytes(6));
    mkdir($this->folder, 0700);
    copy(base_path('tests/Fixtures/workspace-git-incus.py'), $this->folder.'/incus');
    chmod($this->folder.'/incus', 0700);
    workspaceGit($this->folder, 'init', '-q', '--bare', '-b', 'main', $this->folder.'/origin.git');
    workspaceGit($this->folder, 'clone', '-q', $this->folder.'/origin.git', $this->folder.'/default');
    $default = $this->folder.'/default';
    workspaceCommit($default, 'README.md', "readme\n");
    workspaceGit($default, 'push', '-q', 'origin', 'main');
    // Local-only work the default holds: two branches nobody pushed, a stash and an untracked file.
    workspaceGit($default, 'checkout', '-q', '-b', 'feature-a');
    workspaceCommit($default, 'a.txt', "a1\n");
    workspaceCommit($default, 'a.txt', "a2\n");
    workspaceGit($default, 'checkout', '-q', '-b', 'backup', 'main');
    workspaceCommit($default, 'b.txt', "b\n");
    workspaceGit($default, 'checkout', '-q', 'main');
    file_put_contents($default.'/README.md', "stashed\n");
    workspaceGit($default, 'stash', 'push', '-q', '-m', 'kept');
    file_put_contents($default.'/notes.txt', "untracked\n");
    file_put_contents($default.'/.env', "APP_KEY=x\n");
    new LocalProcess(['cp', '-a', $default, $this->folder.'/clone'])->mustRun();
    $this->containers = [
        'instance-1' => ['status' => 'Running', 'repo' => $default],
        'instance-2' => ['status' => 'Running', 'repo' => $this->folder.'/clone'],
    ];
});

afterEach(function (): void {
    new LocalProcess(['rm', '-rf', '--', $this->folder])->run();
});

function workspaceGit(string $directory, string ...$arguments): string
{
    $process = new LocalProcess(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', '-c', 'init.defaultBranch=main', ...$arguments],
        is_dir($directory) ? $directory : null, ['HOME' => sys_get_temp_dir(), 'GIT_CONFIG_NOSYSTEM' => '1']);
    $process->mustRun();

    return $process->getOutput();
}

function workspaceCommit(string $repository, string $file, string $content): void
{
    file_put_contents($repository.'/'.$file, $content);
    workspaceGit($repository, 'add', '--', $file);
    workspaceGit($repository, 'commit', '-q', '-m', 'change '.$file);
}

/**
 * @param  list<string>  $arguments
 * @return array<string, mixed>
 */
function workspaceRead(array $arguments, mixed $baseline = null, float $budget = 2.0, array $published = []): array
{
    $folder = test()->folder;
    file_put_contents($folder.'/containers.json', json_encode(test()->containers, JSON_THROW_ON_ERROR));
    $literal = static fn (mixed $value): string => 'json.loads(base64.b64decode("'.base64_encode(json_encode($value, JSON_THROW_ON_ERROR)).'"))';
    $read = new LocalProcess(['timeout', '3', 'python3', '-', ...$arguments], env: [
        'PATH' => $folder.':/usr/bin:/bin', 'WORKSPACE_READ_FIXTURE' => $folder,
    ]);
    $read->setInput("import base64\nimport json\nINSTANCE_PROJECTS = ".$literal(InstancePlacement::projects())."\nBASELINE = ".$literal($baseline)."\nPUBLISHED = ".$literal($published)."\nBUDGET = ".$budget."\n"
        .file_get_contents(resource_path('instances/workspace-git-read.py')));
    $read->mustRun();

    return json_decode($read->getOutput(), true, 16, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function workspaceRecord(string $container = 'instance-2'): array
{
    return workspaceRead(['record', 'orbit-instances', $container]);
}

/** @return array<string, mixed> */
function workspaceState(string $container, mixed $baseline = null, bool $withDefault = false, array $published = []): array
{
    return workspaceRead(['read', 'orbit-instances', $container, test()->folder.'/no-asleep-marker',
        ...($withDefault ? ['orbit-instances', 'instance-1'] : [])], $baseline, published: $published);
}

it('reads a clone of a default with local-only branches a stash and an untracked file as clean against its baseline', function (): void {
    $baseline = workspaceRecord();
    expect($baseline['untracked'])->toBe(['notes.txt'])->and($baseline['stashes'])->toHaveCount(1)->and($baseline['heads'])->toHaveCount(3);

    expect(workspaceState('instance-2', $baseline))->toBe(['state' => 'clean', 'reason' => null, 'baseline' => 'recorded', 'head' => 'main',
        'modified' => ['count' => 0, 'names' => []], 'untracked' => ['count' => 0, 'names' => []],
        'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => []], 'stashes' => ['count' => 0, 'names' => []]]);
});

it('counts one new commit on an inherited branch, a new untracked file, a changed tracked file and a new stash', function (): void {
    $baseline = workspaceRecord();
    $clone = $this->folder.'/clone';
    workspaceGit($clone, 'checkout', '-q', 'feature-a');
    workspaceCommit($clone, 'a.txt', "a3\n");
    file_put_contents($clone.'/new-file.txt', "new\n");
    file_put_contents($clone.'/a.txt', "edited\n");
    file_put_contents($clone.'/.env.testing', "DB=x\n");

    $read = workspaceState('instance-2', $baseline);
    expect($read['state'])->toBe('dirty')->and($read['head'])->toBe('feature-a')
        ->and($read['commits'])->toBe(['count' => 1, 'branch_count' => 1, 'branches' => [['name' => 'feature-a', 'count' => 1]]])
        ->and($read['untracked'])->toBe(['count' => 1, 'names' => ['new-file.txt']])
        ->and($read['modified'])->toBe(['count' => 1, 'names' => ['a.txt']])
        ->and($read['stashes']['count'])->toBe(0);

    workspaceGit($clone, 'stash', 'push', '-q', '-m', 'mine');
    $read = workspaceState('instance-2', $baseline);
    expect($read['stashes']['count'])->toBe(1)->and($read['stashes']['names'][0])->toEndWith('On feature-a: mine')
        ->and($read['modified']['count'])->toBe(0);
});

it('counts a tracked change also when the baseline holds the same path', function (): void {
    $baseline = workspaceRecord();
    unlink($this->folder.'/clone/README.md');
    expect(workspaceState('instance-2', $baseline)['modified'])->toBe(['count' => 1, 'names' => ['README.md']]);
});

it('compares an Instance without a baseline with the default as it is now, and reads a default plainly', function (): void {
    $fallback = workspaceState('instance-2', null, withDefault: true);
    expect($fallback['baseline'])->toBe('default_now')->and($fallback['commits']['count'])->toBe(0)
        ->and($fallback['stashes']['count'])->toBe(0)
        // The fall-back has no untracked set: untracked files count plainly.
        ->and($fallback['untracked'])->toBe(['count' => 1, 'names' => ['notes.txt']]);

    $plain = workspaceState('instance-1');
    expect($plain['baseline'])->toBe('none')->and($plain['state'])->toBe('dirty')
        ->and($plain['commits'])->toBe(['count' => 3, 'branch_count' => 2,
            'branches' => [['name' => 'backup', 'count' => 1], ['name' => 'feature-a', 'count' => 2]]])
        ->and($plain['stashes']['count'])->toBe(1)->and($plain['untracked']['names'])->toBe(['notes.txt']);

    $this->containers['instance-1']['status'] = 'Stopped';
    expect(workspaceState('instance-2', null, withDefault: true)['baseline'])->toBe('none');
});

it('answers unknown with stopped, asleep, not_a_repository and timeout', function (): void {
    $this->containers['instance-2']['status'] = 'Stopped';
    expect(workspaceState('instance-2'))->toBe(['state' => 'unknown', 'reason' => 'stopped']);

    touch($this->folder.'/asleep');
    expect(workspaceRead(['read', 'orbit-instances', 'instance-2', $this->folder.'/asleep']))->toBe(['state' => 'unknown', 'reason' => 'asleep']);

    $this->containers['instance-2'] = ['status' => 'Running', 'repo' => $this->folder.'/not-a-repository'];
    mkdir($this->folder.'/not-a-repository');
    expect(workspaceState('instance-2'))->toBe(['state' => 'unknown', 'reason' => 'not_a_repository']);

    // The production budget is 2 s (ContainerWorkspaceRead::BudgetSeconds); 0.5 s keeps the test inside its own limit.
    $this->containers['instance-2'] = ['status' => 'Running', 'repo' => $this->folder.'/clone', 'sleep' => 1];
    $started = microtime(true);
    expect(workspaceRead(['read', 'orbit-instances', 'instance-2', $this->folder.'/no-asleep-marker'], budget: 0.5))
        ->toBe(['state' => 'unknown', 'reason' => 'timeout'])->and(microtime(true) - $started)->toBeLessThan(0.9);
});

it('keeps workspace names out of every command line and returns them only as capped JSON text', function (): void {
    $clone = $this->folder.'/clone';
    $hostile = ['$(touch pwned)', '-rf', "line\nbreak", '--output=x', str_repeat('d', 150).'/'.str_repeat('f', 150)];
    mkdir($clone.'/'.str_repeat('d', 150));
    foreach ($hostile as $name) {
        file_put_contents($clone.'/'.$name, 'x');
    }
    workspaceGit($clone, 'checkout', '-q', '-b', 'x$(id)`id`;rm');
    workspaceCommit($clone, 'c.txt', "c\n");

    $read = workspaceState('instance-2', workspaceRecord('instance-1'));
    expect($read['state'])->toBe('dirty')->and($read['untracked']['count'])->toBe(5)
        ->and($read['commits']['branches'])->toBe([['name' => 'x$(id)`id`;rm', 'count' => 1]])
        ->and($read['untracked']['names'])->toContain('$(touch pwned)', '-rf', "line\nbreak", '--output=x')
        ->and(collect($read['untracked']['names'])->map(static fn (string $name): int => mb_strlen($name))->max())->toBeLessThanOrEqual(200)
        ->and(file_exists($clone.'/pwned'))->toBeFalse();
    // The stand-in exits 99 on any argument that is not fixed text or a hex id; every logged call passed that check.
    foreach (file($this->folder.'/argv.log', FILE_IGNORE_NEW_LINES) as $line) {
        expect($line)->not->toContain('pwned')->not->toContain('line\nbreak')->not->toContain('x$(id)');
    }
});

it('counts no commit that a publish pushed, nor its history, and counts a later one', function (): void {
    $baseline = workspaceRecord();
    $clone = $this->folder.'/clone';
    workspaceGit($clone, 'checkout', '-q', '-b', 'task-1');
    workspaceCommit($clone, 'task.txt', "one\n");
    workspaceCommit($clone, 'task.txt', "two\n");
    // The publish pushed task-1 from a bundle: the copy holds no remote ref for it.
    $published = trim(workspaceGit($clone, 'rev-parse', 'HEAD'));

    expect(workspaceState('instance-2', $baseline)['commits']['count'])->toBe(2)
        ->and(workspaceState('instance-2', $baseline, published: [$published])['state'])->toBe('clean');

    workspaceCommit($clone, 'task.txt', "three\n");
    expect(workspaceState('instance-2', $baseline, published: [$published])['commits'])
        ->toBe(['count' => 1, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 1]]])
        // Only a hex id joins rev-list.
        ->and(workspaceState('instance-2', $baseline, published: ['--all', 'HEAD'])['commits']['count'])->toBe(3);
});

it('reads a published head that a clean handoff check left as it was as clean, and still counts real work after it', function (): void {
    $baseline = workspaceRecord();
    $clone = $this->folder.'/clone';
    workspaceGit($clone, 'checkout', '-q', '-b', 'task-1');
    workspaceCommit($clone, 'task.txt', "work\n");
    $published = trim(workspaceGit($clone, 'rev-parse', 'HEAD'));
    // A clean handoff check commits nothing (docs/reference/task-instance-workspace.md#check-phases), so the head stays published.
    expect(workspaceState('instance-2', $baseline, published: [$published])['state'])->toBe('clean');

    // An empty commit would count as unpublished work: the reason the check makes none.
    workspaceGit($clone, 'commit', '-q', '--allow-empty', '-m', 'empty');
    expect(workspaceState('instance-2', $baseline, published: [$published])['commits']['count'])->toBe(1);
    workspaceGit($clone, 'reset', '-q', '--hard', $published);

    file_put_contents($clone.'/task.txt', "uncommitted\n");
    expect(workspaceState('instance-2', $baseline, published: [$published]))
        ->toMatchArray(['state' => 'dirty', 'modified' => ['count' => 1, 'names' => ['task.txt']]]);
    workspaceGit($clone, 'commit', '-q', '-am', 'more work');
    expect(workspaceState('instance-2', $baseline, published: [$published]))
        ->toMatchArray(['state' => 'dirty', 'commits' => ['count' => 1, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 1]]]]);
});

it('passes only hex baseline heads to rev-list and drops an untracked baseline above 2000 entries', function (): void {
    $baseline = workspaceRecord();
    $baseline['heads'][] = '--all';
    $baseline['heads'][] = 'HEAD~1';
    $baseline['stashes'][] = 'not-an-id';
    expect(workspaceState('instance-2', $baseline)['state'])->toBe('clean');

    mkdir($this->folder.'/clone/many');
    foreach (range(1, 2001) as $number) {
        file_put_contents($this->folder.'/clone/many/'.$number.'.txt', '');
    }
    expect(workspaceRecord()['untracked'])->toBeNull();
});

it('skips baseline heads the workspace does not hold, as when the default committed after the copy', function (): void {
    workspaceCommit($this->folder.'/default', 'later.txt', "later\n");
    workspaceGit($this->folder.'/default', 'checkout', '-q', 'feature-a');
    workspaceCommit($this->folder.'/default', 'a.txt', "a-later\n");

    $fallback = workspaceState('instance-2', null, withDefault: true);
    expect($fallback['baseline'])->toBe('default_now')->and($fallback['commits']['count'])->toBe(0)->and($fallback['stashes']['count'])->toBe(0);

    workspaceCommit($this->folder.'/clone', 'mine.txt', "mine\n");
    expect(workspaceState('instance-2', null, withDefault: true)['commits'])
        ->toBe(['count' => 1, 'branch_count' => 1, 'branches' => [['name' => 'main', 'count' => 1]]]);
});

it('leaves out only the untracked Orbit files at the root: a nested .env and a change to a tracked .env count', function (): void {
    $baseline = workspaceRecord();
    $clone = $this->folder.'/clone';
    file_put_contents($clone.'/.env.testing', "x\n");
    expect(workspaceState('instance-2', $baseline)['state'])->toBe('clean');
    mkdir($clone.'/nested');
    file_put_contents($clone.'/nested/.env', "SECRET=mine\n");
    expect(workspaceState('instance-2', $baseline)['untracked'])->toBe(['count' => 1, 'names' => ['nested/.env']]);
    unlink($clone.'/nested/.env');

    workspaceGit($clone, 'add', '-f', '.env');
    expect(workspaceState('instance-2', $baseline)['modified'])->toBe(['count' => 1, 'names' => ['.env']]);
});

it('drops the untracked baseline when the answer would pass the Gateway output limit', function (): void {
    // 1,300 names of about 725 bytes stay below 2,000 entries but pass the 900 KB record limit.
    $wide = $this->folder.'/clone/'.str_repeat('d', 240).'/'.str_repeat('e', 240);
    mkdir($wide, 0700, true);
    foreach (range(1, 1300) as $number) {
        file_put_contents($wide.'/'.str_pad((string) $number, 240, 'x'), '');
    }
    $baseline = workspaceRecord();
    expect($baseline['untracked'])->toBeNull()->and($baseline['heads'])->toHaveCount(3)->and($baseline['stashes'])->toHaveCount(1);
});
