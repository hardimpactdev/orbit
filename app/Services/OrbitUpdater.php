<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;

class OrbitUpdater
{
    public function __construct(
        private readonly PhpExecutableFinder $phpExecutableFinder,
    ) {}

    public function pullSource(): ProcessResult
    {
        return Process::path(base_path())
            ->timeout(60)
            ->run('git pull --ff-only');
    }

    public function installDependencies(): ProcessResult
    {
        $composer = $this->findComposer();

        if ($composer === null) {
            return Process::run('echo "composer not found" >&2; exit 127');
        }

        return Process::path(base_path())
            ->timeout(120)
            ->run([$composer, 'install', '--no-interaction']);
    }

    public function runMigrations(): ProcessResult
    {
        $php = $this->findPhp();

        if ($php === null) {
            return Process::run('echo "CLI PHP binary not found" >&2; exit 127');
        }

        return Process::path(base_path())
            ->timeout(60)
            ->run([$php, 'artisan', 'migrate', '--force']);
    }

    public function updateLocal(): ProcessResult
    {
        $result = $this->pullSource();

        if (! $result->successful()) {
            return $result;
        }

        $result = $this->installDependencies();

        if (! $result->successful()) {
            return $result;
        }

        return $this->runMigrations();
    }

    public function updateRemote(Node $node): RemoteShellResult
    {
        $result = $this->pullRemoteSource($node);

        if (! $result->successful()) {
            return $result;
        }

        $result = $this->installRemoteDependencies($node);

        if (! $result->successful()) {
            return $result;
        }

        return $this->runRemoteMigrations($node);
    }

    public function pullRemoteSource(Node $node): RemoteShellResult
    {
        return $this->runRemote($node, 'git pull --ff-only', 60);
    }

    public function installRemoteDependencies(Node $node): RemoteShellResult
    {
        return $this->runRemote($node, $this->composerInstallCommand(), 120);
    }

    public function runRemoteMigrations(Node $node): RemoteShellResult
    {
        return $this->runRemote($node, 'php artisan migrate --force', 60);
    }

    public function updateCommand(): string
    {
        return 'git pull --ff-only && ('.$this->composerInstallCommand().') && php artisan migrate --force';
    }

    private function composerInstallCommand(): string
    {
        return 'COMPOSER_BIN="$(command -v composer || true)"; '
            .'if [ -z "$COMPOSER_BIN" ] && [ -x "$HOME/.local/bin/composer" ]; then COMPOSER_BIN="$HOME/.local/bin/composer"; fi; '
            .'if [ -z "$COMPOSER_BIN" ]; then echo "composer not found" >&2; exit 127; fi; '
            .'"$COMPOSER_BIN" install --no-interaction';
    }

    private function runRemote(Node $node, string $script, int $timeout): RemoteShellResult
    {
        return app(RemoteShell::class)->run($node, $script, [
            'cwd' => $node->orbit_path,
            'timeout' => $timeout,
        ]);
    }

    private function findComposer(): ?string
    {
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');

        foreach ($paths as $path) {
            $candidate = $path.'/composer';

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        foreach (['/usr/local/bin/composer', '/opt/homebrew/bin/composer', getenv('HOME').'/.local/bin/composer'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function findPhp(): ?string
    {
        $php = $this->phpExecutableFinder->find(false);

        return is_string($php) && $php !== '' ? $php : null;
    }
}
