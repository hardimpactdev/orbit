<?php

declare(strict_types=1);

use App\E2E\GuestTransport;
use App\E2E\Value\AttemptId;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/** Answers the guest script probes from what it was told is installed, and records every call. */
final class WorktreeSynchronizerGuestFake implements GuestTransport
{
    /** @var list<array{instance:string, command:list<string>}> */
    public array $execs = [];

    /** @var list<array{instance:string, source:string, destination:string}> */
    public array $pushes = [];

    /** The script marker as it was pushed; the file is gone afterwards. */
    public ?string $pushedMarker = null;

    /** @param array<string, string> $installed instance => the script marker it answers with */
    public function __construct(public array $installed = [], public ?string $failOn = null) {}

    public function exec(string $instance, GuestCommand $command): GuestCommandResult
    {
        return $this->execAll(['single' => ['instance' => $instance, 'command' => $command]])['single'];
    }

    public function execAll(array $commands): array
    {
        $results = [];
        foreach ($commands as $label => $request) {
            $argv = $request['command']->command;
            $this->execs[] = ['instance' => $request['instance'], 'command' => $argv];
            if ($this->failOn !== null && str_contains(implode(' ', $argv), $this->failOn)) {
                $results[$label] = new GuestCommandResult('', 'failed', 1);
            } elseif ($argv[0] === 'cat') {
                $marker = $this->installed[$request['instance']] ?? null;
                $results[$label] = new GuestCommandResult($marker === null ? '' : $marker."\n", '', $marker === null ? 1 : 0);
            } elseif ($argv[0] === 'sha256sum') {
                $installed = isset($this->installed[$request['instance']]);
                $lines = array_map(static fn (string $path): string => hash_file('sha256', worktreeScriptSource($path)).'  '.$path, array_slice($argv, 2));
                $results[$label] = new GuestCommandResult($installed ? implode("\n", $lines)."\n" : '', '', $installed ? 0 : 1);
            } else {
                $results[$label] = new GuestCommandResult('', '', 0);
            }
        }

        return $results;
    }

    public function pushFile(string $instance, string $source, string $destination): void
    {
        $this->pushes[] = ['instance' => $instance, 'source' => $source, 'destination' => $destination];
        if (str_ends_with($destination, 'guest-scripts.sha256')) {
            $this->pushedMarker = trim((string) file_get_contents($source));
        }
    }

    public function pushFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->pushFile($file['instance'], $file['source'], $file['destination']);
        }
    }
}

function worktreeScriptSource(string $installedPath): string
{
    return dirname(__DIR__, 3).'/resources/guest/'.basename($installedPath);
}

/** @return array{root:string, target:TopologyTarget, synchronizer:WorktreeSynchronizer} */
function worktreeSynchronizerFixture(WorktreeSynchronizerGuestFake $guest, string $branch = 'task-sync-TST-7'): array
{
    $root = temporaryPath('orbit-sync-repo-', 5);
    mkdir($root.'/apps/e2e/resources/guest', 0700, true);
    foreach (WorktreeSynchronizer::GUEST_SCRIPTS as $script) {
        copy(dirname(__DIR__, 3).'/resources/guest/'.$script, $root.'/apps/e2e/resources/guest/'.$script);
        chmod($root.'/apps/e2e/resources/guest/'.$script, 0755);
    }
    foreach ([['init', '-q', '-b', $branch], ['add', '.'], ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-q', '-m', 'fixture']] as $arguments) {
        exec('git -C '.escapeshellarg($root).' '.implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1', $output, $status);
        expect($status)->toBe(0);
    }
    $target = TopologyTarget::for('TST-7', new AttemptId(str_repeat('a', 32)), TopologyRecipe::compose(['app-dev']));

    return [
        'root' => $root,
        'target' => $target,
        'synchronizer' => new WorktreeSynchronizer($guest, $root, new OperationId(str_repeat('c', 32))),
    ];
}

describe('WorktreeSynchronizer', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('installs the guest scripts on every Node and records the mounted source on the checkout Nodes', function () {
        $guest = new WorktreeSynchronizerGuestFake;
        ['root' => $root, 'target' => $target, 'synchronizer' => $synchronizer] = worktreeSynchronizerFixture($guest);

        $source = $synchronizer->syncWorkingTree($target, $root, str_repeat('e', 64));

        $pushedTo = array_values(array_unique(array_column($guest->pushes, 'instance')));
        $markers = array_values(array_filter($guest->execs, static fn (array $exec): bool => in_array(WorktreeSynchronizer::SOURCE_STATE_MARKER, $exec['command'], true)));
        $marker = json_decode($markers[0]['command'][4], true, 8, JSON_THROW_ON_ERROR);

        expect($source)->toBeInstanceOf(SourceState::class)
            ->and($source->mounted)->toBeTrue()
            ->and($source->mountTokenHash)->toBe(str_repeat('e', 64))
            ->and($source->dirty)->toBeFalse()
            ->and($pushedTo)->toBe(array_values($target->instances()))
            ->and(count($guest->pushes))->toBe(3 * (count(WorktreeSynchronizer::GUEST_SCRIPTS) + 1))
            ->and(array_column($markers, 'instance'))->toBe([$target->instance('gateway'), $target->instance('app-dev'), $target->instance('operator')])
            ->and($marker)->toMatchArray(['sha' => $source->hostSha, 'mounted' => true, 'mount_token_sha256' => str_repeat('e', 64)]);
    });

    it('skips the script transfer on a Node whose marker and installed content match', function () {
        $first = new WorktreeSynchronizerGuestFake;
        ['root' => $root, 'target' => $target, 'synchronizer' => $synchronizer] = worktreeSynchronizerFixture($first);
        $synchronizer->installWorkingTreeGuestScripts($target, $root);

        $installed = array_fill_keys(array_values($target->instances()), (string) $first->pushedMarker);
        unset($installed[$target->instance('operator')]);
        $second = new WorktreeSynchronizerGuestFake($installed);
        new WorktreeSynchronizer($second, $root, new OperationId(str_repeat('c', 32)))->installWorkingTreeGuestScripts($target, $root);

        expect($first->pushedMarker)->toMatch('/\A[0-9a-f]{64}\z/')
            ->and(array_values(array_unique(array_column($second->pushes, 'instance'))))->toBe([$target->instance('operator')]);
    });

    it('refuses a worktree on a branch that does not name the issue before any guest call', function () {
        $guest = new WorktreeSynchronizerGuestFake;
        ['root' => $root, 'target' => $target, 'synchronizer' => $synchronizer] = worktreeSynchronizerFixture($guest, 'unrelated');

        expect(fn () => $synchronizer->syncWorkingTree($target, $root, str_repeat('e', 64)))
            ->toThrow(InvalidArgumentException::class, 'branch does not match the issue')
            ->and($guest->execs)->toBe([]);
    });

    it('fails when a guest script cannot be installed and removes the staging directory', function () {
        $guest = new WorktreeSynchronizerGuestFake(failOn: '/usr/local/bin/verify-topology.sh');
        ['root' => $root, 'target' => $target, 'synchronizer' => $synchronizer] = worktreeSynchronizerFixture($guest);

        expect(fn () => $synchronizer->installWorkingTreeGuestScripts($target, $root))
            ->toThrow(RuntimeException::class, 'Guest script installation failed.')
            ->and(array_filter($guest->execs, static fn (array $exec): bool => $exec['command'][0] === 'rm'))->toHaveCount(3);
    });

    it('refuses standalone readiness when the mount token or the source differs from the record', function () {
        $guest = new WorktreeSynchronizerGuestFake;
        ['root' => $root, 'target' => $target, 'synchronizer' => $synchronizer] = worktreeSynchronizerFixture($guest);
        $source = $synchronizer->syncWorkingTree($target, $root, str_repeat('e', 64));

        $synchronizer->assertWorkingTreeMatches($target, $root, $source, str_repeat('e', 64));
        expect(fn () => $synchronizer->assertWorkingTreeMatches($target, $root, $source, str_repeat('f', 64)))
            ->toThrow(RuntimeException::class, 'differs from the last successful readiness record')
            ->and(fn () => $synchronizer->assertWorkingTreeMatches($target, $root, $source, null))
            ->toThrow(RuntimeException::class, 'mount token is missing');

        file_put_contents($root.'/README.md', "edited\n");
        expect(fn () => $synchronizer->assertWorkingTreeMatches($target, $root, $source, str_repeat('e', 64)))
            ->toThrow(RuntimeException::class, 'differs from the last successful readiness record');
    });
});
