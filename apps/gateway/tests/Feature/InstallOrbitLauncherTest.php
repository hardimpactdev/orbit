<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

describe('install-orbit always-cli launcher contract', function (): void {
    it('keeps the installed host command pointed at the downloaded Orbit CLI binary', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('link_target="$TARGET_DIR/bin/orbit-binary"')
            ->toContain('ln -sf "$link_target" "$LINK_PATH"')
            ->toContain('/usr/local/lib/orbit/%s-binary')
            ->not->toContain('ln -sf "$TARGET_DIR/apps/cli/orbit" "$LINK_PATH"')
            ->not->toContain('ln -sf "$TARGET_DIR/artisan" "$LINK_PATH"');
    });

    it('writes a per-operator-host CLI config skeleton during install (D11 + D13)', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('write_cli_config_skeleton')
            ->toContain('.config/orbit/config.json')
            ->toContain('-m 0700')
            ->toContain('-m 0600')
            ->toContain('"schema_version": 1');
    });

    it('writes per-operator-host CLI install metadata after the binary verifies', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('write_install_metadata')
            ->toContain('ORBIT_INSTALL_METADATA_PATH')
            ->toContain('.config/orbit/install.json')
            ->toContain('"installed_at": "$(json_escape "$installed_at")"')
            ->toContain('"binary_path": "$(json_escape "$LINK_PATH")"')
            ->toContain('"install_root": "$(json_escape "$TARGET_DIR")"');

        expect(strrpos($installer, 'verify_install'))->toBeLessThan(strrpos($installer, 'write_install_metadata'));
    });

    it('writes the CLI config skeleton through sudo install so container-owned config roots are repaired', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('owner="$(id -un)"')
            ->toContain('group="$(id -gn)"')
            ->toContain('sudo_run install -d -m 0755 -o "$owner" -g "$group" "$config_parent"')
            ->toContain('sudo_run install -d -m 0700 -o "$owner" -g "$group" "$config_dir"')
            ->toContain('tmp_file="$(mktemp "${TMPDIR:-/tmp}/orbit-cli-config.XXXXXX")"')
            ->toContain('sudo_run install -m 0600 -o "$owner" -g "$group" "$tmp_file" "$config_file"')
            ->not->toContain('cat > "$config_file"');
    });

    it('declares zsh shell integration with same-directory mv snippet replace and append-only zshrc updates', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('ensure_zsh_shell_integration')
            ->toContain("alias orbit='noglob orbit'")
            ->toContain('# >>> orbit zsh integration >>>')
            ->toContain('mktemp "${snippet_dir}/.zsh-noglob.XXXXXX"')
            ->toContain('mv -f "$tmp_file" "$snippet_path"')
            ->toContain('snippet_dir="/.config/orbit/shell"')
            ->toContain('zshrc_path="/.zshrc"')
            ->toContain('source_line=')
            ->toContain('b == 1')
            ->toContain('printf \'%s\' "$block" >>"$zshrc_path"')
            ->not->toContain('existing="$(cat "$zshrc_path"')
            ->not->toContain('install -m 0600 "$tmp_file" "$snippet_path"')
            ->not->toContain('flush_hold')
            ->not->toMatch('/^\s*cat\s+>\s*"\$snippet_path"/m')
            ->not->toContain('setopt nonomatch')
            ->not->toContain('unsetopt nomatch');

        expect(strrpos($installer, 'write_cli_config_skeleton'))
            ->toBeLessThan(strrpos($installer, 'ensure_zsh_shell_integration'));
        expect(strrpos($installer, 'Ensure zsh shell integration'))
            ->toBeLessThan(strrpos($installer, 'Verify Orbit'));
    });

    it('appends a complete canonical block after marker-only orphan without rewriting user bytes', function (): void {
        $root = sys_get_temp_dir().'/orbit-install-zsh-partial-'.bin2hex(random_bytes(4));
        $home = $root.'/home';
        $zshrc = $home.'/.zshrc';
        $prefix = "# >>> orbit zsh integration >>>\nexport ORBIT_ZSH_SENTINEL=keep-me\n# user trailing comment\n";

        try {
            File::ensureDirectoryExists($home);
            File::put($zshrc, $prefix);

            $process = installOrbitEnsureZshIntegrationProcess(home: $home, shell: '/bin/zsh');
            $process->run();

            $contents = File::get($zshrc);

            expect($process->isSuccessful())
                ->toBeTrue($process->getErrorOutput().$process->getOutput())
                ->and(str_starts_with($contents, $prefix))
                ->toBeTrue()
                ->and($contents)
                ->toContain('export ORBIT_ZSH_SENTINEL=keep-me')
                ->and($contents)
                ->toContain('# user trailing comment')
                ->and($contents)
                ->toContain('# <<< orbit zsh integration <<<')
                ->and($contents)
                ->toContain('zsh-noglob.zsh')
                // Orphan BEGIN remains; one complete block is appended after it.
                ->and(substr_count($contents, '# >>> orbit zsh integration >>>'))
                ->toBe(2);

            $second = installOrbitEnsureZshIntegrationProcess(home: $home, shell: '/bin/zsh');
            $second->run();

            expect($second->isSuccessful())
                ->toBeTrue($second->getErrorOutput().$second->getOutput())
                ->and(File::get($zshrc))
                ->toBe($contents)
                ->and(File::get($zshrc))
                ->toContain('export ORBIT_ZSH_SENTINEL=keep-me')
                ->and(substr_count(File::get($zshrc), '# >>> orbit zsh integration >>>'))
                ->toBe(2);
        } finally {
            if (is_dir($root)) {
                File::deleteDirectory($root);
            }
        }
    });

    it('rejects same-line prefix+BEGIN and END+suffix and appends a canonical block leaving malformed bytes', function (): void {
        $root = sys_get_temp_dir().'/orbit-install-zsh-malformed-'.bin2hex(random_bytes(4));
        $home = $root.'/home';
        $zshrc = $home.'/.zshrc';
        $snippet = $home.'/.config/orbit/shell/zsh-noglob.zsh';
        // Match installer printf %q / escapeshellarg for plain paths.
        $source = '[ -f '.escapeshellarg($snippet).' ] && . '.escapeshellarg($snippet);

        try {
            File::ensureDirectoryExists($home);

            $malformedPrefix =
                'export KEEP_PREFIX=1 # >>> orbit zsh integration >>>'
                ."\n"
                .$source
                ."\n"
                ."# <<< orbit zsh integration <<<\n";
            File::put($zshrc, $malformedPrefix);

            $first = installOrbitEnsureZshIntegrationProcess(home: $home, shell: '/bin/zsh');
            $first->run();
            $afterPrefix = File::get($zshrc);

            expect($first->isSuccessful())
                ->toBeTrue($first->getErrorOutput().$first->getOutput())
                ->and(str_starts_with($afterPrefix, $malformedPrefix))
                ->toBeTrue()
                ->and($afterPrefix)
                ->toContain('export KEEP_PREFIX=1')
                ->and(substr_count($afterPrefix, '# >>> orbit zsh integration >>>'))
                ->toBeGreaterThan(1);

            $second = installOrbitEnsureZshIntegrationProcess(home: $home, shell: '/bin/zsh');
            $second->run();
            expect($second->isSuccessful())->toBeTrue()->and(File::get($zshrc))->toBe($afterPrefix);

            $malformedEnd =
                "# >>> orbit zsh integration >>>\n"
                .$source
                ."\n"
                ."# <<< orbit zsh integration <<< trailing-garbage\n"
                ."export KEEP_END=1\n";
            File::put($zshrc, $malformedEnd);

            $third = installOrbitEnsureZshIntegrationProcess(home: $home, shell: '/bin/zsh');
            $third->run();
            $afterEnd = File::get($zshrc);

            expect($third->isSuccessful())
                ->toBeTrue($third->getErrorOutput().$third->getOutput())
                ->and(str_starts_with($afterEnd, $malformedEnd))
                ->toBeTrue()
                ->and($afterEnd)
                ->toContain('export KEEP_END=1')
                ->and($afterEnd)
                ->toContain(' trailing-garbage');

            $fourth = installOrbitEnsureZshIntegrationProcess(home: $home, shell: '/bin/zsh');
            $fourth->run();
            expect($fourth->isSuccessful())->toBeTrue()->and(File::get($zshrc))->toBe($afterEnd);
        } finally {
            if (is_dir($root)) {
                File::deleteDirectory($root);
            }
        }
    });

    it('resolves installer root HOME and ZDOTDIR paths without writing real root', function (): void {
        $installer = File::get(repo_path('bin/install-orbit'));

        // Shape contract: root branches must produce exact PHP-aligned paths.
        expect($installer)
            ->toContain('if [ "$target_home" = "/" ]; then')
            ->toContain('snippet_dir="/.config/orbit/shell"')
            ->toContain('if [ "$ZDOTDIR" = "/" ]; then')
            ->toContain('zshrc_path="/.zshrc"');

        // Pure shell evaluation of path-join logic only (no ensure, no root writes).
        $script = <<<'BASH'
            set -euo pipefail
            target_home="/"
            if [ "$target_home" = "/" ]; then
                snippet_dir="/.config/orbit/shell"
            else
                snippet_dir="${target_home%/}/.config/orbit/shell"
            fi
            snippet_path="${snippet_dir}/zsh-noglob.zsh"
            ZDOTDIR="/"
            if [ -n "${ZDOTDIR:-}" ]; then
                if [ "$ZDOTDIR" = "/" ]; then
                    zshrc_path="/.zshrc"
                else
                    zshrc_path="${ZDOTDIR%/}/.zshrc"
                fi
            elif [ "$target_home" = "/" ]; then
                zshrc_path="/.zshrc"
            else
                zshrc_path="${target_home%/}/.zshrc"
            fi
            printf 'snippet=%s\n' "$snippet_path"
            printf 'zshrc=%s\n' "$zshrc_path"
            BASH;

        $process = new Process(['bash', '-c', $script]);
        $process->run();

        expect($process->isSuccessful())
            ->toBeTrue()
            ->and($process->getOutput())
            ->toContain('snippet=/.config/orbit/shell/zsh-noglob.zsh')
            ->and($process->getOutput())
            ->toContain('zshrc=/.zshrc');
    });

    it('executes ensure_zsh_shell_integration for zsh with symlink-safe snippet and zshrc append', function (): void {
        $root = sys_get_temp_dir().'/orbit-install-zsh-'.bin2hex(random_bytes(4));
        $home = $root.'/home';
        $dotfiles = $home.'/dotfiles';
        $hostileTarget = $root.'/hostile-target';
        $zshrcTarget = $dotfiles.'/zshrc';
        $snippetPath = $home.'/.config/orbit/shell/zsh-noglob.zsh';

        try {
            File::ensureDirectoryExists($dotfiles);
            File::ensureDirectoryExists($home.'/.config/orbit/shell');
            File::put($hostileTarget, "do-not-truncate\n");
            File::put($zshrcTarget, 'export CUSTOM=1'); // no trailing newline
            symlink($hostileTarget, $snippetPath);
            symlink($zshrcTarget, $home.'/.zshrc');
            chmod($zshrcTarget, 0640);

            expect(is_link($snippetPath))->toBeTrue()->and(readlink($snippetPath))->toBe($hostileTarget);

            $process = installOrbitEnsureZshIntegrationProcess(
                home: $home,
                shell: '/bin/zsh',
            );
            $process->run();

            expect($process->isSuccessful())
                ->toBeTrue($process->getErrorOutput().$process->getOutput())
                // Replaced the snippet path itself; did not write through the symlink.
                ->and(is_link($snippetPath))
                ->toBeFalse()
                ->and(is_file($snippetPath))
                ->toBeTrue()
                ->and(file_get_contents($snippetPath))
                ->toContain("alias orbit='noglob orbit'")
                ->and(file_get_contents($hostileTarget))
                ->toBe("do-not-truncate\n")
                ->and(is_link($home.'/.zshrc'))
                ->toBeTrue()
                ->and(readlink($home.'/.zshrc'))
                ->toBe($zshrcTarget)
                ->and(substr(sprintf('%o', fileperms($zshrcTarget)), -4))
                ->toBe('0640')
                ->and(file_get_contents($zshrcTarget))
                ->toStartWith("export CUSTOM=1\n")
                ->and(file_get_contents($zshrcTarget))
                ->toContain('# >>> orbit zsh integration >>>')
                ->and(file_get_contents($zshrcTarget))
                ->toContain('zsh-noglob.zsh');

            // Idempotent second run leaves a single managed block and still leaves
            // the former hostile symlink target untouched.
            $second = installOrbitEnsureZshIntegrationProcess(home: $home, shell: '/bin/zsh');
            $second->run();

            expect($second->isSuccessful())
                ->toBeTrue($second->getErrorOutput().$second->getOutput())
                ->and(substr_count(file_get_contents($zshrcTarget), '# >>> orbit zsh integration >>>'))
                ->toBe(1)
                ->and(file_get_contents($hostileTarget))
                ->toBe("do-not-truncate\n")
                ->and(is_link($snippetPath))
                ->toBeFalse();
        } finally {
            if (is_dir($root)) {
                File::deleteDirectory($root);
            }
        }
    });

    it('executes ensure_zsh_shell_integration against ZDOTDIR when it differs from HOME', function (): void {
        $root = sys_get_temp_dir().'/orbit-install-zsh-zdotdir-'.bin2hex(random_bytes(4));
        $home = $root.'/home';
        $zdotdir = $root.'/zdotdir';

        try {
            File::ensureDirectoryExists($home);
            File::ensureDirectoryExists($zdotdir);

            $process = installOrbitEnsureZshIntegrationProcess(
                home: $home,
                shell: '/bin/zsh',
                zdotdir: $zdotdir,
            );
            $process->run();

            $snippet = $home.'/.config/orbit/shell/zsh-noglob.zsh';
            $zshrc = $zdotdir.'/.zshrc';

            expect($process->isSuccessful())
                ->toBeTrue($process->getErrorOutput().$process->getOutput())
                ->and(is_file($snippet))
                ->toBeTrue()
                ->and(file_get_contents($snippet))
                ->toContain("alias orbit='noglob orbit'")
                ->and(is_file($zshrc))
                ->toBeTrue()
                ->and(file_get_contents($zshrc))
                ->toContain('# >>> orbit zsh integration >>>')
                ->and(File::exists($home.'/.zshrc'))
                ->toBeFalse();
        } finally {
            if (is_dir($root)) {
                File::deleteDirectory($root);
            }
        }
    });

    it('skips ensure_zsh_shell_integration when the active shell is not zsh', function (): void {
        $root = sys_get_temp_dir().'/orbit-install-zsh-bash-'.bin2hex(random_bytes(4));
        $home = $root.'/home';

        try {
            File::ensureDirectoryExists($home);

            $process = installOrbitEnsureZshIntegrationProcess(
                home: $home,
                shell: '/bin/bash',
            );
            $process->run();

            expect($process->isSuccessful())
                ->toBeTrue($process->getErrorOutput().$process->getOutput())
                ->and(File::exists($home.'/.zshrc'))
                ->toBeFalse()
                ->and(File::exists($home.'/.config/orbit/shell/zsh-noglob.zsh'))
                ->toBeFalse()
                ->and($process->getOutput().$process->getErrorOutput())
                ->toContain('skipping zsh shell integration');
        } finally {
            if (is_dir($root)) {
                File::deleteDirectory($root);
            }
        }
    });

    it('dispatches public commands through the source CLI entrypoint', function (): void {
        $capture = orbitLauncherProbe(arguments: ['node:list', '--json']);

        expect($capture['target'])
            ->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])
            ->toBe('cli')
            ->and($capture['args'])
            ->toBe('[node:list][--json]');
    });

    it('dispatches internal commands through the same source CLI entrypoint', function (): void {
        $capture = orbitLauncherProbe(arguments: ['internal:wg-easy:state', '--json']);

        expect($capture['target'])
            ->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])
            ->toBe('cli')
            ->and($capture['args'])
            ->toBe('[internal:wg-easy:state][--json]');
    });

    it('routes internal commands through the same wrapper path without special handling', function (): void {
        $capture = orbitLauncherProbe(arguments: ['internal:database-query-local', '--json']);

        expect($capture['target'])
            ->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])
            ->toBe('cli')
            ->and($capture['args'])
            ->toBe('[internal:database-query-local][--json]');
    });

    it('defaults unconfigured nodes to the source CLI entrypoint', function (): void {
        $capture = orbitLauncherProbe(arguments: ['node:doctor']);

        expect($capture['target'])
            ->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])
            ->toBe('cli')
            ->and($capture['args'])
            ->toBe('[node:doctor]');
    });

    it('propagates wrapper arguments even when flags are present', function (): void {
        $capture = orbitLauncherProbe(arguments: ['--json', 'node:list', '--no-interaction']);

        expect($capture['target'])
            ->toBe($capture['repo'].'/apps/cli/orbit')
            ->and($capture['ORBIT_APP'])
            ->toBe('cli')
            ->and($capture['args'])
            ->toBe('[--json][node:list][--no-interaction]');
    });

    it('resolves the repo root from the wrapper location instead of using a production default', function (): void {
        $launcher = File::get(repo_path('bin/orbit'));

        expect($launcher)
            ->toContain('resolve_default_repo')
            ->toContain('apps/cli/orbit')
            ->not->toContain('/home/orbit/orbit');
    });

    it('fails clearly when the local CLI artifact dependencies are missing', function (): void {
        $cli = File::get(repo_path('apps/cli/orbit'));

        expect($cli)
            ->toContain("__DIR__.'/vendor/autoload.php'")
            ->toContain('Orbit CLI dependencies are not installed')
            ->not->toContain("__DIR__.'/../../autoload.php'");
    });

    it('keeps the CLI artifact free of the removed bridge launcher path', function (): void {
        $launcher = File::get(repo_path('apps/cli/orbit'));

        expect($launcher)
            ->toContain("__DIR__.'/NativeCommandNormalizer.php'")
            ->not->toContain('CompatibilityBridge.php')
            ->not->toContain("dirname(__DIR__, 2).'/apps/gateway/artisan'");

        expect(File::exists(repo_path('apps/cli/CompatibilityBridge.php')))->toBeFalse();
    });

    it('keeps the convenience wrapper free of env-file reads secret bridging and allow-list logic', function (): void {
        $launcher = File::get(repo_path('bin/orbit'));

        expect($launcher)
            ->not->toContain('apps/gateway/.env')
            ->not->toContain('is_local_executor_command');
    });

    it('keeps the CLI config focused on client-side gateway and executor settings', function (): void {
        $config = require repo_path('apps/cli/config/orbit.php');

        expect(array_keys($config))->toBe(['gateway', 'local_executor_binary']);
    });
});

/**
 * Source and run ensure_zsh_shell_integration from bin/install-orbit with local
 * fail/run stubs so the installer path is exercised without a full install.
 */
function installOrbitEnsureZshIntegrationProcess(string $home, string $shell, ?string $zdotdir = null): Process
{
    $installer = repo_path('bin/install-orbit');
    $script = <<<'BASH'
        set -euo pipefail

        fail() {
            local code="$1"
            shift
            printf 'orbit-install: error [%s] %s\n' "$code" "$*" >&2
            exit 1
        }

        run() {
            "$@"
        }

        # shellcheck disable=SC1090
        source /dev/stdin <<<"$(sed -n '/^ensure_zsh_shell_integration()/,/^}$/p' "$ORBIT_INSTALLER_PATH")"
        ensure_zsh_shell_integration
        BASH;

    $env = [
        'HOME' => $home,
        'ORBIT_CONFIG_HOME' => $home,
        'SHELL' => $shell,
        'ORBIT_INSTALLER_PATH' => $installer,
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin',
        'TMPDIR' => sys_get_temp_dir(),
    ];

    if ($zdotdir !== null) {
        $env['ZDOTDIR'] = $zdotdir;
    }

    return new Process(
        ['bash', '-c', $script],
        null,
        $env,
    );
}

/**
 * @param  list<string>  $arguments
 * @return array<string, string>
 */
function orbitLauncherProbe(array $arguments): array
{
    $root = sys_get_temp_dir().'/orbit-launcher-contract-'.bin2hex(random_bytes(4));

    try {
        $home = "{$root}/home/orbit";
        $repo = "{$home}/orbit";
        $hostCwd = "{$root}/caller/project";
        $capturePath = "{$root}/launcher-capture";

        orbitLauncherPrepareFakeCheckout($repo);
        File::ensureDirectoryExists($hostCwd);

        $process = new Process(
            [$repo.'/bin/orbit', ...$arguments],
            $hostCwd,
            ['HOME' => $home, 'ORBIT_LAUNCHER_CAPTURE' => $capturePath],
        );

        $process->run();

        expect($process->getExitCode())
            ->toBe(
                0,
                $process->getErrorOutput().$process->getOutput(),
            );
        expect(File::exists($capturePath))->toBeTrue('expected the launcher to execute a fake Orbit artifact');

        return (
            orbitLauncherReadCapture($capturePath)
            + [
                'repo' => $repo,
                'host_cwd' => $hostCwd,
            ]
        );
    } finally {
        if (is_dir($root)) {
            File::deleteDirectory($root);
        }
    }
}

function orbitLauncherPrepareFakeCheckout(string $repo): void
{
    File::ensureDirectoryExists("{$repo}/bin");
    File::ensureDirectoryExists("{$repo}/apps/cli");

    File::copy(repo_path('bin/orbit'), "{$repo}/bin/orbit");
    chmod("{$repo}/bin/orbit", 0755);

    orbitLauncherWriteExecutable("{$repo}/apps/cli/orbit", orbitLauncherCaptureScript());
}

function orbitLauncherWriteExecutable(string $path, string $contents): void
{
    File::put($path, $contents);
    chmod($path, 0755);
}

function orbitLauncherCaptureScript(): string
{
    return <<<'BASH'
        #!/usr/bin/env bash
        set -Eeuo pipefail
        {
            printf 'target=%s\n' "$0"
            printf 'ORBIT_APP=%s\n' "${ORBIT_APP:-}"
            printf 'args='
            for arg in "$@"; do
                printf '[%s]' "$arg"
            done
            printf '\n'
        } > "$ORBIT_LAUNCHER_CAPTURE"
        BASH;
}

/**
 * @return array<string, string>
 */
function orbitLauncherReadCapture(string $path): array
{
    $capture = [];

    foreach (explode(PHP_EOL, trim(File::get($path))) as $line) {
        [$key, $value] = explode('=', $line, 2);
        $capture[$key] = $value;
    }

    return $capture;
}
