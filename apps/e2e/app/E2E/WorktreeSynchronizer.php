<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Installs the worktree's guest scripts on every Node and records the identity of the mounted worktree.
 * The guests read application source from the live mount; nothing is copied.
 */
final readonly class WorktreeSynchronizer
{
    /** @var list<string> */
    public const array GUEST_SCRIPTS = [
        'converge-node.sh',
        'converge-gateway.sh',
        'converge-operator.sh',
        'prepare-node.sh',
        'verify-topology.sh',
    ];

    /** Where a mounted checkout records its host identity for the readiness probes. */
    public const string SOURCE_STATE_MARKER = '/var/lib/orbit-e2e/source-state';

    private const string SCRIPT_MARKER = '/var/lib/orbit-e2e/guest-scripts.sha256';

    public function __construct(
        private GuestTransport $incus,
        private string $repositoryRoot,
        private OperationId $operation,
    ) {}

    /**
     * Install the current guest scripts on every Node, then record the host identity of the mounted
     * worktree on every checkout Node.
     */
    public function syncWorkingTree(TopologyTarget $target, string $worktree, string $mountTokenHash): SourceState
    {
        $repository = $this->installWorkingTreeGuestScriptsFrom($target, $worktree);
        ['source' => $source, 'effectiveTreeHash' => $treeHash] = $this->mountedSourceState($repository, $mountTokenHash);
        $marker = json_encode([
            'sha' => $source->hostSha,
            'tree' => $treeHash,
            'mounted' => true,
            'mount_token_sha256' => $source->mountTokenHash,
        ], JSON_THROW_ON_ERROR);

        $commands = [];
        foreach ($target->recipe->checkoutNodeKeys() as $node) {
            $commands["source-marker.{$node}"] = [
                'instance' => $target->instance($node),
                'command' => new GuestCommand([
                    'sh',
                    '-c',
                    'umask 022 && install -d -m 0755 -- "$(dirname -- "$2")" '
                        .'&& printf \'%s\\n\' "$1" > "$2.tmp" && mv -f -- "$2.tmp" "$2"',
                    'orbit-e2e',
                    $marker,
                    self::SOURCE_STATE_MARKER,
                ], 30),
            ];
        }
        $this->assertBatchSuccessful($this->incus->execAll($commands), 'Guest source marker write failed.');

        return $source;
    }

    /** Install the worktree's guest scripts without writing the source marker; `sync --quick` records nothing. */
    public function installWorkingTreeGuestScripts(TopologyTarget $target, string $worktree): void
    {
        $this->installWorkingTreeGuestScriptsFrom($target, $worktree);
    }

    /** Refuse standalone readiness when the mount differs from the last successful source record. */
    public function assertWorkingTreeMatches(
        TopologyTarget $target,
        string $worktree,
        SourceState $expected,
        ?string $mountTokenHash,
    ): void {
        $repository = new GitRepository($worktree);
        $this->validateWorktree($repository, $target);
        if ($mountTokenHash === null) {
            throw new RuntimeException('The worktree mount token is missing; run topology sync.');
        }
        ['source' => $current] = $this->mountedSourceState($repository, $mountTokenHash);
        $matches = $expected->mounted
            && $current->hostSha === $expected->hostSha
            && $current->dirty === $expected->dirty
            && $current->treeHash === $expected->treeHash
            && $current->overlayPaths === $expected->overlayPaths
            && $current->mountTokenHash === $expected->mountTokenHash;

        if (! $matches) {
            throw new RuntimeException(
                'The mounted source differs from the last successful readiness record; run topology sync.',
            );
        }
    }

    /** @return array{source:SourceState,effectiveTreeHash:string} */
    private function mountedSourceState(GitRepository $repository, string $mountTokenHash): array
    {
        $hostSha = $repository->commit();
        $overlay = $repository->dirtyOverlay();

        return [
            'source' => new SourceState(
                $hostSha,
                $hostSha,
                $overlay !== null,
                $overlay?->treeHash,
                $overlay->paths ?? [],
                $this->operation->value,
                true,
                $mountTokenHash,
            ),
            'effectiveTreeHash' => $overlay->treeHash ?? $repository->effectiveTreeHash(),
        ];
    }

    private function installWorkingTreeGuestScriptsFrom(TopologyTarget $target, string $worktree): GitRepository
    {
        $repository = new GitRepository($worktree);
        $this->validateWorktree($repository, $target);
        $this->installGuestScripts($target, $repository->root());

        return $repository;
    }

    /** Install the worktree's guest scripts on every Node whose installed scripts differ. */
    private function installGuestScripts(TopologyTarget $target, string $worktreeRoot): void
    {
        $scripts = $this->guestScripts($worktreeRoot);
        $hash = $this->guestScriptHash($scripts);
        $status = $this->unchangedGuestScripts($target, $scripts, $hash);
        $changed = [];
        foreach ($target->recipe->nodeKeys() as $node) {
            if (! $status[$node]) {
                $changed[$node] = $target->instance($node);
            }
        }
        if ($changed === []) {
            return;
        }

        $markerFile = sys_get_temp_dir().'/orbit-e2e-guest-scripts-'.bin2hex(random_bytes(12));
        try {
            if (file_put_contents($markerFile, $hash."\n", LOCK_EX) === false || ! chmod($markerFile, 0600)) {
                throw new RuntimeException('Could not create the guest script marker.');
            }
            $this->pushGuestScripts($changed, $scripts, $markerFile);
        } finally {
            if (is_file($markerFile)) {
                unlink($markerFile);
            }
        }
    }

    private function validateWorktree(GitRepository $repository, TopologyTarget $target): void
    {
        $this->validateRepositoryIdentity($repository);
        if (! $target->matchesBranch($repository->branch())) {
            throw new InvalidArgumentException('The worktree branch does not match the issue.');
        }
    }

    /** The source must share the Git common directory of the repository this harness serves. */
    private function validateRepositoryIdentity(GitRepository $repository): void
    {
        $expected = realpath($this->repositoryRoot);
        $worktreeRoot = $repository->root();
        if ($expected === false) {
            throw new InvalidArgumentException('The expected repository does not exist.');
        }

        $common = trim($this->git($worktreeRoot, ['rev-parse', '--git-common-dir']));
        $expectedCommon = trim($this->git($expected, ['rev-parse', '--git-common-dir']));
        $commonReal = realpath(str_starts_with($common, '/') ? $common : $worktreeRoot.'/'.$common);
        $expectedReal = realpath(
            str_starts_with($expectedCommon, '/') ? $expectedCommon : $expected.'/'.$expectedCommon,
        );
        if ($commonReal === false || $expectedReal === false || $commonReal !== $expectedReal) {
            throw new InvalidArgumentException('The worktree repository identity does not match.');
        }
    }

    /** @return list<string> */
    private function guestScripts(string $worktreeRoot): array
    {
        $directory = $worktreeRoot.'/apps/e2e/resources/guest';
        $paths = [];
        foreach (self::GUEST_SCRIPTS as $name) {
            $path = $directory.'/'.$name;
            if (is_link($path) || ! is_file($path) || ! is_executable($path)) {
                throw new RuntimeException('Guest script inventory is invalid.');
            }

            $paths[] = $path;
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @param list<string> $scripts */
    private function guestScriptHash(array $scripts): string
    {
        $context = hash_init('sha256');
        foreach ($scripts as $script) {
            $permissions = fileperms($script);
            $contents = file_get_contents($script);
            if ($permissions === false || $contents === false) {
                throw new RuntimeException('Could not read guest script inventory.');
            }
            hash_update($context, basename($script)."\0".sprintf('%o', $permissions & 07777)."\0".$contents."\0");
        }

        return hash_final($context);
    }

    /**
     * @param  list<string>  $scripts
     * @return array<string, bool>
     */
    private function unchangedGuestScripts(TopologyTarget $target, array $scripts, string $hash): array
    {
        $installedScripts = array_map(
            static fn (string $script): string => '/usr/local/bin/'.basename($script),
            $scripts,
        );
        $expectedContentHashes = $this->guestScriptContentHashes($installedScripts, $scripts);
        $commands = [];
        foreach ($target->recipe->nodeKeys() as $node) {
            $instance = $target->instance($node);
            $commands["script-marker.{$node}"] = [
                'instance' => $instance,
                'command' => new GuestCommand(['cat', self::SCRIPT_MARKER]),
            ];
            $commands["script-content.{$node}"] = [
                'instance' => $instance,
                'command' => new GuestCommand(['sha256sum', '--', ...$installedScripts]),
            ];
        }
        $results = $this->incus->execAll($commands);
        $status = [];
        foreach ($target->recipe->nodeKeys() as $node) {
            $markerProbe = $results["script-marker.{$node}"] ?? null;
            $contentProbe = $results["script-content.{$node}"] ?? null;
            if (! $markerProbe instanceof GuestCommandResult || ! $contentProbe instanceof GuestCommandResult) {
                throw new RuntimeException('Guest script probe batch result is invalid.');
            }
            $status[$node] =
                $markerProbe->successful()
                && trim($markerProbe->stdout) === $hash
                && $contentProbe->successful()
                && trim($contentProbe->stdout) === $expectedContentHashes;
        }

        return $status;
    }

    /**
     * @param  array<string, string>  $instances
     * @param  list<string>  $scripts
     */
    private function pushGuestScripts(array $instances, array $scripts, string $markerFile): void
    {
        $prefix = "/var/lib/orbit-e2e/scripts/{$this->operation->value}";
        try {
            $prepare = [];
            foreach ($instances as $node => $instance) {
                $prepare["script-prepare.{$node}"] = [
                    'instance' => $instance,
                    'command' => new GuestCommand(['install', '-d', '-m', '0700', $prefix]),
                ];
            }
            $this->assertBatchSuccessful($this->incus->execAll($prepare), 'Guest script staging failed.');

            $pushes = [];
            foreach ($instances as $node => $instance) {
                foreach ($scripts as $script) {
                    $name = basename($script);
                    $pushes["script-push.{$node}.{$name}"] = [
                        'instance' => $instance,
                        'source' => $script,
                        'destination' => "{$prefix}/{$name}",
                    ];
                }
                $pushes["script-push.{$node}.marker"] = [
                    'instance' => $instance,
                    'source' => $markerFile,
                    'destination' => "{$prefix}/guest-scripts.sha256",
                ];
            }
            $this->incus->pushFiles($pushes);

            $installs = [];
            foreach ($instances as $node => $instance) {
                $commands = [];
                foreach ($scripts as $script) {
                    $name = basename($script);
                    $commands[] = 'install -o root -g root -m 0755 '.escapeshellarg("{$prefix}/{$name}")
                        .' '.escapeshellarg("/usr/local/bin/{$name}");
                }
                $commands[] = 'install -o root -g root -m 0644 '.escapeshellarg("{$prefix}/guest-scripts.sha256")
                    .' '.escapeshellarg(self::SCRIPT_MARKER);
                $installs["script-install.{$node}"] = [
                    'instance' => $instance,
                    'command' => new GuestCommand(['sh', '-ec', implode("\n", $commands)]),
                ];
            }
            $this->assertBatchSuccessful($this->incus->execAll($installs), 'Guest script installation failed.');
        } finally {
            $cleanup = [];
            foreach ($instances as $node => $instance) {
                $cleanup["script-cleanup.{$node}"] = [
                    'instance' => $instance,
                    'command' => new GuestCommand(['rm', '-rf', '--', $prefix]),
                ];
            }
            try {
                $this->assertBatchSuccessful($this->incus->execAll($cleanup), 'Guest script staging cleanup failed.');
            } catch (Throwable) {
                // A staging directory left behind holds only copies of the public scripts.
            }
        }
    }

    /**
     * @param  list<string>  $installedScripts
     * @param  list<string>  $scripts
     */
    private function guestScriptContentHashes(array $installedScripts, array $scripts): string
    {
        $lines = [];
        foreach ($scripts as $index => $script) {
            $contentHash = hash_file('sha256', $script);
            if ($contentHash === false) {
                throw new RuntimeException('Could not hash guest script inventory.');
            }
            $lines[] = $contentHash.'  '.$installedScripts[$index];
        }

        return implode("\n", $lines);
    }

    /** @param array<string, GuestCommandResult> $results */
    private function assertBatchSuccessful(array $results, string $message): void
    {
        $failed = [];
        foreach ($results as $label => $result) {
            if (! $result->successful()) {
                $failed[] = $label;
            }
        }
        if ($failed !== []) {
            throw new RuntimeException($message.' Failed operations: '.implode(', ', $failed).'.');
        }
    }

    /** @param list<string> $arguments */
    private function git(string $path, array $arguments): string
    {
        $result = Process::path($path)->run(['git', ...$arguments]);
        if ($result->failed()) {
            throw new InvalidArgumentException('Git repository validation failed.');
        }

        return $result->output();
    }
}
