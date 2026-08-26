<?php

declare(strict_types=1);

use App\Tools\VitePlusTool;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('declares the managed viteplus lifecycle contract', function (): void {
    $tool = new VitePlusTool;

    expect($tool->supportedOperatingSystems())
        ->toBe(['linux', 'macos'])
        ->and($tool->capabilities())
        ->toBe(['install', 'update', 'remove', 'safe-adopt']);
});

it('installs viteplus and its lts node environment for the managed user', function (): void {
    $script = new VitePlusTool()->installScript(['managed_user' => 'nckrtl']);

    expect($script)
        ->toContain('https://vite.plus')
        ->and($script)
        ->toContain('env install lts')
        ->and($script)
        ->toContain('env default lts')
        ->and($script)
        ->toContain('env setup')
        ->and($script)
        ->toContain('/usr/local/bin/vp')
        ->and($script)
        ->toContain('/usr/local/bin/${binary}')
        ->and($script)
        ->toContain('VP_HOME=/opt/orbit/vite-plus')
        ->and($script)
        ->toContain('is_orbit_viteplus_link "${link}"')
        ->and($script)
        ->toContain('nckrtl');

    expect($script)->toContain(
        'MANAGED_GROUP="$(id -gn "${MANAGED_USER}")"',
        'install -d -o "${MANAGED_USER}" -g "${MANAGED_GROUP}" "${MANAGED_HOME}/.local" "${MANAGED_HOME}/.local/share"',
        'sudo install -d -m 0755 /opt/orbit/vite-plus',
        'sudo chmod -R a+rX /opt/orbit/vite-plus',
    );

    expect($script)->not->toContain('sudo install -d -m 0755 /opt/orbit /opt/orbit/vite-plus');
});

it('supports both viteplus home layouts when exposing stable links', function (): void {
    $script = new VitePlusTool()->installScript();

    expect($script)
        ->toContain('.local/share/vite-plus')
        ->and($script)
        ->toContain('.vite-plus')
        ->and($script)
        ->toContain('Darwin')
        ->and($script)
        ->toContain('/Users/${MANAGED_USER}')
        ->and($script)
        ->toContain('/home/${MANAGED_USER}')
        ->and($script)
        ->toContain("bash -lc 'command -v vp'");
});

it('updates, removes, adopts, and probes the stable viteplus entry points', function (): void {
    $tool = new VitePlusTool;

    expect($tool->updateScript())
        ->toContain('vp upgrade')
        ->and($tool->removeScript())
        ->toContain('implode --yes')
        ->and(strpos(haystack: $tool->removeScript(), needle: 'rm -f'))
        ->toBeLessThan(strpos(haystack: $tool->removeScript(), needle: 'implode --yes'))
        ->and($tool->probeMetadata())
        ->toMatchArray([
            'binary' => '/usr/local/bin/vp',
        ]);

    expect($tool->probeMetadata()['version_command'])
        ->toContain('test -L "${link}"')
        ->toContain('test "$(readlink "${link}")" = "${target}"')
        ->toContain('test -x "${target}"')
        ->toContain('/usr/local/bin/node --version')
        ->toContain('/usr/local/bin/npm --version')
        ->toContain('/usr/local/bin/npx --version');

    expect($tool->probeMetadata()['probe'])
        ->toContain('test -L "${link}"')
        ->toContain('test "$(readlink "${link}")" = "${target}"')
        ->toContain('test -x "${target}"');

    expect($tool->updateScript())->toContain('sudo chmod -R a+rX /opt/orbit/vite-plus');

    expect($tool->removeScript())->toContain('if [ -n "${VP}" ]');
    expect($tool->removeScript())
        ->toContain('is_orbit_viteplus_link "${link}"')
        ->toContain('/opt/orbit/vite-plus/*')
        ->toContain('rm -f "${link}"')
        ->toContain('implode --yes || test ! -e "${VP}"')
        ->toContain('sudo rm -rf /opt/orbit/vite-plus');
});

it('preflights every viteplus source and rejects foreign stable path occupants before relinking', function (): void {
    $script = new VitePlusTool()->installScript();
    $sourceCheck = 'test -x "${target}"';
    $conflict = "printf 'Vite+ link conflict: %s exists and is not an Orbit-managed Vite+ symlink.\\n' \"\${link}\" >&2";
    $relink = 'sudo ln -sfn "${target}" "${link}"';

    expect($script)
        ->toContain($sourceCheck)
        ->toContain($conflict)
        ->toContain($relink)
        ->and(strpos(haystack: $script, needle: $sourceCheck))
        ->toBeLessThan(strpos(haystack: $script, needle: $relink))
        ->and(strpos(haystack: $script, needle: $conflict))
        ->toBeLessThan(strpos(haystack: $script, needle: $relink));
});

it('rejects a foreign executable in the doctor-facing viteplus probe', function (): void {
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-viteplus-probe-'.bin2hex(random_bytes(8));
    $sourceDirectory = "{$root}/source";
    $stableDirectory = "{$root}/stable";

    $filesystem->ensureDirectoryExists($sourceDirectory);
    $filesystem->ensureDirectoryExists($stableDirectory);

    try {
        foreach (['vp', 'node', 'npm', 'npx'] as $binary) {
            $source = "{$sourceDirectory}/{$binary}";
            file_put_contents(filename: $source, data: "#!/usr/bin/env bash\nprintf '%s\\n' 'test-version'\n");
            chmod(filename: $source, permissions: 0o755);
            symlink($source, "{$stableDirectory}/{$binary}");
        }

        $probe = str_replace(
            ['/usr/local/bin', '/opt/orbit/vite-plus/bin'],
            [$stableDirectory, $sourceDirectory],
            new VitePlusTool()->probeMetadata()['probe'],
        );

        $healthy = Process::fromShellCommandline($probe);
        $healthy->run();
        expect($healthy->isSuccessful())->toBeTrue($healthy->getErrorOutput());

        unlink("{$stableDirectory}/node");
        symlink('/bin/true', "{$stableDirectory}/node");

        $foreign = Process::fromShellCommandline($probe);
        $foreign->run();
        expect($foreign->isSuccessful())->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($root);
    }
});

it('emits bash syntax-valid viteplus lifecycle scripts', function (): void {
    $tool = new VitePlusTool;

    foreach ([$tool->installScript(), $tool->updateScript(), $tool->removeScript()] as $script) {
        $process = new Process(['bash', '-n']);
        $process->setInput($script);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }
});
