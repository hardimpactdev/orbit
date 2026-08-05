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

        expect($process->isSuccessful())
            ->toBeFalse()
            ->and($process->getErrorOutput().$process->getOutput())
            ->toMatch('/no matches found: --add=process:\*/');
    });

    it('preserves literal --add=process:* argv under interactive zsh with Orbit integration', function (): void {
        $integration = new ZshShellIntegration;
        $result = $integration->ensure(home: $this->home, shell: '/bin/zsh');

        $snippet = file_get_contents($result['snippet_path']);
        $zshrc = file_get_contents($result['zshrc_path']);

        expect($result['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and($integration->succeeded($result))
            ->toBeTrue()
            ->and($result['snippet_path'])
            ->toBe($this->home.'/.config/orbit/shell/zsh-noglob.zsh')
            ->and($result['zshrc_path'])
            ->toBe($this->home.'/.zshrc')
            ->and(is_file($result['snippet_path']))
            ->toBeTrue()
            ->and($snippet)
            ->toContain("alias orbit='noglob orbit'")
            ->and($snippet)
            ->not->toMatch('/\bsetopt\s+[^\n]*nonomatch\b/i')->and($snippet)
            ->not->toMatch('/\bunsetopt\s+[^\n]*nomatch\b/i')->and(
                $zshrc,
            )->toContain(ZshShellIntegration::BEGIN_MARKER)->and($zshrc)->toContain(ZshShellIntegration::END_MARKER);

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
        new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');

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

        expect($process->isSuccessful())
            ->toBeFalse()
            ->and($process->getErrorOutput().$process->getOutput())
            ->toMatch('/no matches found: nosuch-glob-file-\*/');
    });

    it('skips installation when the active shell is not zsh', function (): void {
        $result = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/bash');

        expect($result['status'])
            ->toBe(ZshShellIntegration::STATUS_SKIPPED_NOT_ZSH)
            ->and(new ZshShellIntegration()->succeeded($result))
            ->toBeTrue()
            ->and(file_exists($this->home.'/.zshrc'))
            ->toBeFalse()
            ->and(file_exists($this->home.'/.config/orbit/shell/zsh-noglob.zsh'))
            ->toBeFalse();
    });

    it('requires an exact shell basename of zsh and rejects suffix lookalikes', function (): void {
        $integration = new ZshShellIntegration;

        expect($integration->isZsh('/bin/zsh'))
            ->toBeTrue()
            ->and($integration->isZsh('/usr/local/bin/zsh'))
            ->toBeTrue()
            ->and($integration->isZsh('/bin/bash'))
            ->toBeFalse()
            ->and($integration->isZsh('/bin/not-zsh'))
            ->toBeFalse()
            ->and($integration->isZsh('/bin/zsh-5.9'))
            ->toBeFalse();

        $result = $integration->ensure(home: $this->home, shell: '/bin/not-zsh');

        expect($result['status'])
            ->toBe(ZshShellIntegration::STATUS_SKIPPED_NOT_ZSH)
            ->and(file_exists($this->home.'/.zshrc'))
            ->toBeFalse()
            ->and(file_exists($this->home.'/.config/orbit/shell/zsh-noglob.zsh'))
            ->toBeFalse();
    });

    it('is idempotent and rewrites only the managed snippet on upgrade', function (): void {
        $integration = new ZshShellIntegration;
        $first = $integration->ensure(home: $this->home, shell: '/bin/zsh');

        $zshrcPath = $this->home.'/.zshrc';
        $snippetPath = $this->home.'/.config/orbit/shell/zsh-noglob.zsh';
        $originalZshrc = file_get_contents($zshrcPath);

        file_put_contents($snippetPath, "# stale snippet\n");

        $second = $integration->ensure(home: $this->home, shell: '/bin/zsh');

        expect($first['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and($second['status'])
            ->toBe(ZshShellIntegration::STATUS_ALREADY_PRESENT)
            ->and(file_get_contents($zshrcPath))
            ->toBe($originalZshrc)
            ->and(file_get_contents($snippetPath))
            ->toContain("alias orbit='noglob orbit'")
            ->and(file_get_contents($snippetPath))
            ->not
            ->toContain('# stale snippet')
            ->and(substr_count(file_get_contents($zshrcPath), ZshShellIntegration::BEGIN_MARKER))
            ->toBe(1);
    });

    it('appends through a .zshrc symlink without replacing it', function (): void {
        $target = $this->home.'/dotfiles/zshrc';
        $zshrc = $this->home.'/.zshrc';
        mkdir(dirname($target), 0700, recursive: true);
        file_put_contents($target, "export CUSTOM=1\n");
        chmod($target, 0640);
        symlink($target, $zshrc);

        $result = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        $contents = file_get_contents($target);

        expect($result['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(is_link($zshrc))
            ->toBeTrue()
            ->and(readlink($zshrc))
            ->toBe($target)
            ->and(substr(sprintf('%o', fileperms($target)), -4))
            ->toBe('0640')
            ->and($contents)
            ->toContain('export CUSTOM=1')
            ->and($contents)
            ->toContain(ZshShellIntegration::BEGIN_MARKER)
            ->and($contents)
            ->toContain(ZshShellIntegration::snippetRelativePath());
    });

    it('replaces a hostile snippet symlink without truncating its target', function (): void {
        $snippetDir = $this->home.'/.config/orbit/shell';
        $snippetPath = $snippetDir.'/zsh-noglob.zsh';
        $hostileTarget = $this->root.'/hostile-target';
        mkdir($snippetDir, 0700, recursive: true);
        file_put_contents($hostileTarget, "do-not-truncate\n");
        symlink($hostileTarget, $snippetPath);

        $result = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');

        expect($result['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(is_link($snippetPath))
            ->toBeFalse()
            ->and(is_file($snippetPath))
            ->toBeTrue()
            ->and(file_get_contents($snippetPath))
            ->toContain("alias orbit='noglob orbit'")
            ->and(file_get_contents($hostileTarget))
            ->toBe("do-not-truncate\n");
    });

    it('appends the managed block under ZDOTDIR when ZDOTDIR differs from HOME', function (): void {
        $zdotdir = $this->root.'/zdotdir';
        $home = $this->home;
        mkdir($zdotdir, 0700, recursive: true);

        $result = new ZshShellIntegration()->ensure(
            home: $home,
            shell: '/bin/zsh',
            zdotdir: $zdotdir,
        );

        $snippet = $home.'/.config/orbit/shell/zsh-noglob.zsh';
        $zshrc = $zdotdir.'/.zshrc';

        expect($result['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and($result['zshrc_path'])
            ->toBe($zshrc)
            ->and(is_file($snippet))
            ->toBeTrue()
            ->and(file_get_contents($snippet))
            ->toContain("alias orbit='noglob orbit'")
            ->and(is_file($zshrc))
            ->toBeTrue()
            ->and(file_get_contents($zshrc))
            ->toContain(ZshShellIntegration::BEGIN_MARKER)
            ->and(file_exists($home.'/.zshrc'))
            ->toBeFalse();

        // Fresh interactive zsh with ZDOTDIR != HOME preserves unquoted process:*.
        file_put_contents($zshrc, zsh_capture_function_only().file_get_contents($zshrc));
        $process = zsh_interactive_orbit_invocation($zdotdir, $home);

        expect($process->isSuccessful())
            ->toBeTrue($process->getErrorOutput().$process->getOutput())
            ->and($process->getOutput())
            ->toContain('ARG:--add=process:*');
    });

    it('appends through a ZDOTDIR .zshrc symlink without replacing it', function (): void {
        $zdotdir = $this->root.'/zdotdir';
        $target = $this->root.'/dotfiles/zshrc';
        mkdir(dirname($target), 0700, recursive: true);
        mkdir($zdotdir, 0700, recursive: true);
        file_put_contents($target, "export CUSTOM=1\n");
        chmod($target, 0640);
        symlink($target, $zdotdir.'/.zshrc');

        $result = new ZshShellIntegration()->ensure(
            home: $this->home,
            shell: '/bin/zsh',
            zdotdir: $zdotdir,
        );

        expect($result['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(is_link($zdotdir.'/.zshrc'))
            ->toBeTrue()
            ->and(readlink($zdotdir.'/.zshrc'))
            ->toBe($target)
            ->and(substr(sprintf('%o', fileperms($target)), -4))
            ->toBe('0640')
            ->and(file_get_contents($target))
            ->toContain(ZshShellIntegration::BEGIN_MARKER)
            ->and(file_exists($this->home.'/.zshrc'))
            ->toBeFalse();

        $second = new ZshShellIntegration()->ensure(
            home: $this->home,
            shell: '/bin/zsh',
            zdotdir: $zdotdir,
        );

        expect($second['status'])
            ->toBe(ZshShellIntegration::STATUS_ALREADY_PRESENT)
            ->and(substr_count(file_get_contents($target), ZshShellIntegration::BEGIN_MARKER))
            ->toBe(1);
    });

    it('preserves root directory paths for HOME and ZDOTDIR without writing root', function (): void {
        $integration = new ZshShellIntegration;

        // Pure path resolution only — never call ensure() with home/zdotdir `/`.
        $rootHome = $integration->resolvePaths('/');
        $rootZdot = $integration->resolvePaths($this->home, '/');
        $slashed = $integration->resolvePaths($this->home.'/', $this->root.'/zdotdir/');

        expect($rootHome)
            ->toBe([
                'snippet_path' => '/.config/orbit/shell/zsh-noglob.zsh',
                'zshrc_path' => '/.zshrc',
            ])
            ->and($rootZdot)
            ->toBe([
                'snippet_path' => $this->home.'/.config/orbit/shell/zsh-noglob.zsh',
                'zshrc_path' => '/.zshrc',
            ])
            ->and($slashed)
            ->toBe([
                'snippet_path' => $this->home.'/.config/orbit/shell/zsh-noglob.zsh',
                'zshrc_path' => $this->root.'/zdotdir/.zshrc',
            ])
            ->and($integration->resolvePaths(''))
            ->toBeNull();
    });

    it('appends a complete canonical block after a begin-marker-only orphan without rewriting user bytes', function (): void {
        $zshrc = $this->home.'/.zshrc';
        $snippet = $this->home.'/.config/orbit/shell/zsh-noglob.zsh';
        $prefix =
            ZshShellIntegration::BEGIN_MARKER."\n"."export ORBIT_ZSH_SENTINEL=keep-me\n"."# user trailing comment\n";
        file_put_contents($zshrc, $prefix);

        $first = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        $contents = file_get_contents($zshrc);

        expect($first['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(str_starts_with($contents, $prefix))
            ->toBeTrue()
            ->and($contents)
            ->toContain('export ORBIT_ZSH_SENTINEL=keep-me')
            ->and($contents)
            ->toContain('# user trailing comment')
            ->and(ZshShellIntegration::hasCompleteManagedBlock($contents, $snippet))
            ->toBeTrue()
            // Orphan BEGIN remains; one complete block is appended after it.
            ->and(substr_count($contents, ZshShellIntegration::BEGIN_MARKER))
            ->toBe(2);

        $second = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        $after = file_get_contents($zshrc);

        expect($second['status'])
            ->toBe(ZshShellIntegration::STATUS_ALREADY_PRESENT)
            ->and($after)
            ->toBe($contents)
            ->and($after)
            ->toContain('export ORBIT_ZSH_SENTINEL=keep-me')
            ->and(substr_count($after, ZshShellIntegration::BEGIN_MARKER))
            ->toBe(2);
    });

    it('rejects same-line prefix+BEGIN and END+suffix as incomplete and appends a canonical block', function (): void {
        $zshrc = $this->home.'/.zshrc';
        $snippet = $this->home.'/.config/orbit/shell/zsh-noglob.zsh';
        $source = ZshShellIntegration::sourceLine($snippet);

        // Prefix glued to BEGIN on one line — must not count as a complete block.
        $malformedPrefix =
            'export KEEP_PREFIX=1 '
            .ZshShellIntegration::BEGIN_MARKER
            ."\n"
            .$source
            ."\n"
            .ZshShellIntegration::END_MARKER
            ."\n";

        expect(ZshShellIntegration::hasCompleteManagedBlock($malformedPrefix, $snippet))->toBeFalse();

        file_put_contents($zshrc, $malformedPrefix);
        $first = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        $afterPrefix = file_get_contents($zshrc);

        expect($first['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(str_starts_with($afterPrefix, $malformedPrefix))
            ->toBeTrue()
            ->and($afterPrefix)
            ->toContain('export KEEP_PREFIX=1')
            ->and(ZshShellIntegration::hasCompleteManagedBlock($afterPrefix, $snippet))
            ->toBeTrue();

        $second = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        expect($second['status'])
            ->toBe(ZshShellIntegration::STATUS_ALREADY_PRESENT)
            ->and(file_get_contents($zshrc))
            ->toBe($afterPrefix);

        // END glued to suffix on one line — must not count as complete either.
        $malformedEnd =
            ZshShellIntegration::BEGIN_MARKER
            ."\n"
            .$source
            ."\n"
            .ZshShellIntegration::END_MARKER
            ." trailing-garbage\n"
            ."export KEEP_END=1\n";

        expect(ZshShellIntegration::hasCompleteManagedBlock($malformedEnd, $snippet))->toBeFalse();

        file_put_contents($zshrc, $malformedEnd);
        $third = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        $afterEnd = file_get_contents($zshrc);

        expect($third['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(str_starts_with($afterEnd, $malformedEnd))
            ->toBeTrue()
            ->and($afterEnd)
            ->toContain('export KEEP_END=1')
            ->and($afterEnd)
            ->toContain(' trailing-garbage')
            ->and(ZshShellIntegration::hasCompleteManagedBlock($afterEnd, $snippet))
            ->toBeTrue();

        $fourth = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        expect($fourth['status'])
            ->toBe(ZshShellIntegration::STATUS_ALREADY_PRESENT)
            ->and(file_get_contents($zshrc))
            ->toBe($afterEnd);

        // CRLF-terminated "block" is non-canonical (installer/PHP use LF only).
        $crlfBlock =
            ZshShellIntegration::BEGIN_MARKER
            ."\r\n"
            .$source
            ."\r\n"
            .ZshShellIntegration::END_MARKER
            ."\r\n"
            ."export KEEP_CRLF=1\r\n";

        expect(ZshShellIntegration::hasCompleteManagedBlock($crlfBlock, $snippet))->toBeFalse();

        file_put_contents($zshrc, $crlfBlock);
        $fifth = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        $afterCrlf = file_get_contents($zshrc);

        expect($fifth['status'])
            ->toBe(ZshShellIntegration::STATUS_INSTALLED)
            ->and(str_starts_with($afterCrlf, $crlfBlock))
            ->toBeTrue()
            ->and($afterCrlf)
            ->toContain("export KEEP_CRLF=1\r\n")
            ->and($afterCrlf)
            ->toContain("\r\n")
            ->and(ZshShellIntegration::hasCompleteManagedBlock($afterCrlf, $snippet))
            ->toBeTrue()
            ->and(str_contains($afterCrlf, ZshShellIntegration::zshrcBlock($snippet)))
            ->toBeTrue();

        $sixth = new ZshShellIntegration()->ensure(home: $this->home, shell: '/bin/zsh');
        expect($sixth['status'])
            ->toBe(ZshShellIntegration::STATUS_ALREADY_PRESENT)
            ->and(file_get_contents($zshrc))
            ->toBe($afterCrlf);
    });

    it('fails coherently when HOME cannot be resolved for a zsh shell', function (): void {
        // Explicit empty home is distinguishable from null (process HOME fallback).
        $result = new ZshShellIntegration()->ensure(home: '', shell: '/bin/zsh');

        expect($result['status'])
            ->toBe(ZshShellIntegration::STATUS_FAILED)
            ->and(new ZshShellIntegration()->succeeded($result))
            ->toBeFalse()
            ->and($result['message'])
            ->toContain('HOME');

        // Also prove process-env isolation when HOME is temporarily unset.
        $previousHome = getenv('HOME');
        $previousServerHome = $_SERVER['HOME'] ?? null;

        try {
            putenv('HOME');
            unset($_SERVER['HOME']);

            $envResult = new ZshShellIntegration()->ensure(home: null, shell: '/bin/zsh');

            expect($envResult['status'])
                ->toBe(ZshShellIntegration::STATUS_FAILED)
                ->and($envResult['message'])
                ->toContain('HOME');
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
