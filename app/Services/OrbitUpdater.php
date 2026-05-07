<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class OrbitUpdater
{
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
        return Process::path(base_path())
            ->timeout(60)
            ->run([PHP_BINARY, 'artisan', 'migrate', '--force']);
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
        return app(RemoteShell::class)->run($node, $this->updateCommand(), [
            'cwd' => $node->orbit_path,
            'timeout' => 600,
        ]);
    }

    public function updateCommand(): string
    {
        return 'COMPOSER_BIN="$(command -v composer || true)"; '
            .'if [ -z "$COMPOSER_BIN" ] && [ -x "$HOME/.local/bin/composer" ]; then COMPOSER_BIN="$HOME/.local/bin/composer"; fi; '
            .'if [ -z "$COMPOSER_BIN" ]; then echo "composer not found" >&2; exit 127; fi; '
            .'git pull --ff-only && "$COMPOSER_BIN" install --no-interaction && php artisan migrate --force';
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
}
