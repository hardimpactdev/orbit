<?php

declare(strict_types=1);

use App\Tools\VitePlusTool;

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
    );
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
        ->toContain('/usr/local/bin/node --version')
        ->toContain('/usr/local/bin/npm --version')
        ->toContain('/usr/local/bin/npx --version');

    expect($tool->removeScript())->toContain('if [ -n "${VP}" ]');
    expect($tool->removeScript())
        ->toContain('is_orbit_viteplus_link "${link}"')
        ->toContain('/opt/orbit/vite-plus/*')
        ->toContain('rm -f "${link}"');
});
