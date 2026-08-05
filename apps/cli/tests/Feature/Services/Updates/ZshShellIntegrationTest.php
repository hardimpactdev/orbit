<?php

declare(strict_types=1);

use App\Services\Updates\ZshShellIntegration;
use Symfony\Component\Process\Process;

/**
 * Shell-boundary regression for zsh NOMATCH on unquoted namespace wildcards.
 *
 * Alias + invocation in the same `zsh -c` parse does not expand the alias.
 * Startup testing must not use `-f` (skips rc files). Use interactive zsh with
 * ZDOTDIR so `.zshrc` is loaded before the command string is parsed.
 */
describe('ZshShellIntegration shell boundary', function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir().'/orbit-zsh-integration-'.bin2hex(random_bytes(4));
        $this->home = $this->root.'/home';
        $this->zdotdir = $this->home;
        mkdir($this->home, 0700, recursive: true);
    });

    afterEach(function (): void {
        if (is_dir($this->root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($this->root);
        }
    });

    it('fails with zsh NOMATCH for unquoted --add=process:* without Orbit integration', function (): void {
        file_put_contents($this->zdotdir.'/.zshrc', zsh_capture_function_only());

        $process = zsh_interactive_orbit_invocation($this->zdotdir, $this->home);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput().$process->getOutput())
            ->toMatch('/no matches found: --add=process:\*/');
    });

    it('preserves literal --add=process:* argv under interactive zsh with Orbit integration', function (): void {
        $integration = new ZshShellIntegration;
        $result = $integration->ensure(home: $this->home, shell: '/bin/zsh');

        $snippet = file_get_contents($result['snippet_path']);
        $zshrc = file_get_contents($result['zshrc_path']);

        expect($result['status'])->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and($integration->succeeded($result))->toBeTrue()
            ->and($result['snippet_path'])->toBe($this->home.'/.config/orbit/shell/zsh-noglob.zsh')
            ->and($result['zshrc_path'])->toBe($this->home.'/.zshrc')
            ->and(is_file($result['snippet_path']))->toBeTrue()
            ->and($snippet)->toContain("alias orbit='noglob orbit'")
            ->and($snippet)->not->toMatch('/\bsetopt\s+[^\n]*nonomatch\b/i')
            ->and($snippet)->not->toMatch('/\bunsetopt\s+[^\n]*nomatch\b/i')
            ->and($zshrc)->toContain(ZshShellIntegration::BEGIN_MARKER)
            ->and($zshrc)->toContain(ZshShellIntegration::END_MARKER);

        // Prepend a capture function so the alias targets a local function rather than a real binary.
        $zshrc = file_get_contents($this->home.'/.zshrc');
        file_put_contents($this->home.'/.zshrc', zsh_capture_function_only().$zshrc);

        $process = zsh_interactive_orbit_invocation($this->zdotdir, $this->home);

        expect($process->isSuccessful())
            ->toBeTrue($process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain('ARGC=4')
            ->and($process->getOutput())
            ->toContain('ARG:--add=process:*')
            ->and($process->getErrorOutput().$process->getOutput())
            ->not->toMatch('/no matches found/');
    });

    it('does not weaken glob behavior for non-Orbit commands', function (): void {
        (new ZshShellIntegration)->ensure(home: $this->home, shell: '/bin/zsh');

        $process = new Process(
            ['zsh', '-i', '-c', 'ls nosuch-glob-file-*'],
            $this->home,
            [
                'HOME' => $this->home,
                'ZDOTDIR' => $this->zdotdir,
                'PATH' => '/usr/bin:/bin',
            ],
        );
        $process->setTimeout(10);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput().$process->getOutput())
            ->toMatch('/no matches found: nosuch-glob-file-\*/');
    });

    it('skips installation when the active shell is not zsh', function (): void {
        $result = (new ZshShellIntegration)->ensure(home: $this->home, shell: '/bin/bash');

        expect($result['status'])->toBe(ZshShellIntegration::STATUS_SKIPPED_NOT_ZSH)
            ->and((new ZshShellIntegration)->succeeded($result))->toBeTrue()
            ->and(file_exists($this->home.'/.zshrc'))->toBeFalse()
            ->and(file_exists($this->home.'/.config/orbit/shell/zsh-noglob.zsh'))->toBeFalse();
    });

    it('is idempotent and rewrites only the managed snippet on upgrade', function (): void {
        $integration = new ZshShellIntegration;
        $first = $integration->ensure(home: $this->home, shell: '/bin/zsh');

        $zshrcPath = $this->home.'/.zshrc';
        $snippetPath = $this->home.'/.config/orbit/shell/zsh-noglob.zsh';
        $originalZshrc = file_get_contents($zshrcPath);

        file_put_contents($snippetPath, "# stale snippet\n");

        $second = $integration->ensure(home: $this->home, shell: '/bin/zsh');

        expect($first['status'])->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and($second['status'])->toBe(ZshShellIntegration::STATUS_ALREADY_PRESENT)
            ->and(file_get_contents($zshrcPath))->toBe($originalZshrc)
            ->and(file_get_contents($snippetPath))->toContain("alias orbit='noglob orbit'")
            ->and(file_get_contents($snippetPath))->not->toContain('# stale snippet')
            ->and(substr_count(file_get_contents($zshrcPath), ZshShellIntegration::BEGIN_MARKER))->toBe(1);
    });

    it('appends through a .zshrc symlink without replacing it', function (): void {
        $target = $this->home.'/dotfiles/zshrc';
        $zshrc = $this->home.'/.zshrc';
        mkdir(dirname($target), 0700, recursive: true);
        file_put_contents($target, "export CUSTOM=1\n");
        chmod($target, 0640);
        symlink($target, $zshrc);

        $result = (new ZshShellIntegration)->ensure(home: $this->home, shell: '/bin/zsh');
        $contents = file_get_contents($target);

        expect($result['status'])->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(is_link($zshrc))->toBeTrue()
            ->and(readlink($zshrc))->toBe($target)
            ->and(substr(sprintf('%o', fileperms($target)), -4))->toBe('0640')
            ->and($contents)->toContain('export CUSTOM=1')
            ->and($contents)->toContain(ZshShellIntegration::BEGIN_MARKER)
            ->and($contents)->toContain(ZshShellIntegration::snippetRelativePath());
    });

    it('replaces a hostile snippet symlink without truncating its target', function (): void {
        $snippetDir = $this->home.'/.config/orbit/shell';
        $snippetPath = $snippetDir.'/zsh-noglob.zsh';
        $hostileTarget = $this->root.'/hostile-target';
        mkdir($snippetDir, 0700, recursive: true);
        file_put_contents($hostileTarget, "do-not-truncate\n");
        symlink($hostileTarget, $snippetPath);

        $result = (new ZshShellIntegration)->ensure(home: $this->home, shell: '/bin/zsh');

        expect($result['status'])->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(is_link($snippetPath))->toBeFalse()
            ->and(is_file($snippetPath))->toBeTrue()
            ->and(file_get_contents($snippetPath))->toContain("alias orbit='noglob orbit'")
            ->and(file_get_contents($hostileTarget))->toBe("do-not-truncate\n");
    });

    it('fails coherently when HOME cannot be resolved for a zsh shell', function (): void {
        // Explicit empty home is distinguishable from null (process HOME fallback).
        $result = (new ZshShellIntegration)->ensure(home: '', shell: '/bin/zsh');

        expect($result['status'])->toBe(ZshShellIntegration::STATUS_FAILED)
            ->and((new ZshShellIntegration)->succeeded($result))->toBeFalse()
            ->and($result['message'])->toContain('HOME');

        // Also prove process-env isolation when HOME is temporarily unset.
        $previousHome = getenv('HOME');
        $previousServerHome = $_SERVER['HOME'] ?? null;

        try {
            putenv('HOME');
            unset($_SERVER['HOME']);

            $envResult = (new ZshShellIntegration)->ensure(home: null, shell: '/bin/zsh');

            expect($envResult['status'])->toBe(ZshShellIntegration::STATUS_FAILED)
                ->and($envResult['message'])->toContain('HOME');
        } finally {
            if ($previousHome === false) {
                putenv('HOME');
            } else {
                putenv("HOME={$previousHome}");
            }

            if ($previousServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $previousServerHome;
            }
        }
    });
});

function zsh_capture_function_only(): string
{
    return <<<'ZSH'
orbit() {
  print -r -- "ARGC=$#"
  for a; do
    print -r -- "ARG:$a"
  done
}

ZSH;
}

function zsh_interactive_orbit_invocation(string $zdotdir, string $home): Process
{
    $process = new Process(
        ['zsh', '-i', '-c', 'orbit node:permissions beast main1 --add=process:*'],
        $home,
        [
            'HOME' => $home,
            'ZDOTDIR' => $zdotdir,
            'PATH' => '/usr/bin:/bin',
        ],
    );
    $process->setTimeout(10);
    $process->run();

    return $process;
}
