<?php

declare(strict_types=1);

namespace App\E2E\Git;

use App\E2E\Value\DirtyOverlay;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

final readonly class GitRepository
{
    public function __construct(
        private string $path,
    ) {}

    public function root(): string
    {
        $root = realpath(trim($this->run(['rev-parse', '--show-toplevel'])));

        if ($root === false) {
            throw new InvalidArgumentException('The Git repository root does not exist.');
        }

        return $root;
    }

    public function branch(): string
    {
        $branch = trim($this->run(['symbolic-ref', '--quiet', '--short', 'HEAD']));

        if ($branch === '' || preg_match('/[\0\r\n]/', $branch) === 1) {
            throw new InvalidArgumentException('The Git branch is invalid.');
        }

        return $branch;
    }

    /** The shared Git directory every linked worktree of one repository resolves to. */
    public function commonDirectory(): string
    {
        $commonDirectory = realpath(trim($this->run([
            'rev-parse',
            '--path-format=absolute',
            '--git-common-dir',
        ])));

        if ($commonDirectory === false) {
            throw new InvalidArgumentException('The Git directories do not exist.');
        }

        return $commonDirectory;
    }

    public function dirtyOverlay(): ?DirtyOverlay
    {
        $root = $this->root();
        $records = $this->run(['status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignore-submodules=none']);
        if ($records === '') {
            return null;
        }

        $paths = [];
        $parts = explode("\0", $records);
        for ($index = 0; $index < count($parts); $index++) {
            $record = $parts[$index];
            if ($record === '') {
                continue;
            }
            if (strlen($record) < 4 || $record[2] !== ' ') {
                throw new InvalidArgumentException('Git returned an invalid dirty inventory.');
            }
            $status = substr($record, 0, 2);
            if (str_contains($status, 'U') || in_array($status, ['AA', 'DD'], true)) {
                throw new InvalidArgumentException('Merge conflicts cannot be synchronized.');
            }
            $path = substr($record, 3);
            if ($status[0] === 'R' || $status[0] === 'C' || $status[1] === 'R' || $status[1] === 'C') {
                $previousPath = $parts[++$index] ?? '';
                $this->validateOverlayPath($previousPath);
                $paths[] = $previousPath;
            }
            $this->validateOverlayPath($path);
            $this->validateOverlayEntry($path, $status, $root);
            $paths[] = $path;
        }

        sort($paths, SORT_STRING);

        return new DirtyOverlay(array_values(array_unique($paths)), $this->effectiveTreeHash());
    }

    public function effectiveTreeHash(): string
    {
        $index = tempnam(sys_get_temp_dir(), 'orbit-index-');
        if ($index === false) {
            throw new RuntimeException('Could not create the temporary Git index.');
        }
        unlink($index);

        try {
            $environment = ['GIT_INDEX_FILE' => $index];
            foreach ([['read-tree', 'HEAD'], ['add', '-A', '--', '.']] as $arguments) {
                $result = Process::path($this->path)->env($environment)->run(['git', ...$arguments]);
                if ($result->failed()) {
                    throw new InvalidArgumentException('Git could not calculate the effective tree.');
                }
            }
            $result = Process::path($this->path)->env($environment)->run(['git', 'write-tree']);
            if ($result->failed() || preg_match('/\A[0-9a-f]{40}\n?\z/D', $result->output()) !== 1) {
                throw new InvalidArgumentException('Git returned an invalid effective tree.');
            }
            $hash = hash('sha256', trim($result->output()));
        } catch (\Throwable $exception) {
            if (file_exists($index) && ! unlink($index)) {
                throw new RuntimeException('Could not remove the temporary Git index.', previous: $exception);
            }

            throw $exception;
        }

        if (file_exists($index) && ! unlink($index)) {
            throw new RuntimeException('Could not remove the temporary Git index.');
        }

        return $hash;
    }

    private function validateOverlayPath(string $path): void
    {
        new DirtyOverlay([$path], str_repeat('0', 64));
        if (
            preg_match('~(?:\A|/)(?:\.git|vendor|node_modules)(?:/|\z)~i', $path) === 1
            || preg_match('~(?:\A|/)(?:\.env(?:\.|\z)|credentials?(?:\.|/|\z)|id_[rd]sa(?:\.|\z))~i', $path) === 1
        ) {
            throw new InvalidArgumentException("Dirty path [{$path}] is prohibited.");
        }
    }

    private function validateOverlayEntry(string $path, string $status, string $root): void
    {
        if (str_contains($status, 'D')) {
            return;
        }
        $absolute = $root.'/'.$path;
        $parent = dirname($absolute);
        while ($parent !== $root) {
            if (is_link($parent)) {
                throw new InvalidArgumentException('Dirty paths cannot traverse a symlink parent.');
            }

            $next = dirname($parent);
            if ($next === $parent || ! str_starts_with($next.'/', $root.'/')) {
                throw new InvalidArgumentException('Dirty path parent escaped the repository.');
            }
            $parent = $next;
        }
        if (is_link($absolute)) {
            throw new InvalidArgumentException('Dirty symlinks cannot be synchronized.');
        }
        if (! is_file($absolute)) {
            throw new InvalidArgumentException('Dirty entries must be regular files.');
        }
        $staged = trim($this->run(['ls-files', '--stage', '--', $path]));
        if (str_starts_with($staged, '160000 ')) {
            throw new InvalidArgumentException('Submodules cannot be synchronized.');
        }
    }

    public function commit(string $revision = 'HEAD'): string
    {
        if ($revision === '' || str_contains($revision, "\0") || preg_match('/[\r\n]/', $revision) === 1) {
            throw new InvalidArgumentException('The Git revision is invalid.');
        }

        $commit = strtolower(trim($this->run(['rev-parse', '--verify', "{$revision}^{commit}"])));

        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new InvalidArgumentException('Git did not return a full commit SHA.');
        }

        return $commit;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $result = Process::path($this->path)->run(['git', ...$arguments]);

        if ($result->failed()) {
            throw new InvalidArgumentException('The Git command failed.');
        }

        return $result->output();
    }
}
