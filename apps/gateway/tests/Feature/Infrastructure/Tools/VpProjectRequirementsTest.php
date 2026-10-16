<?php

declare(strict_types=1);

use App\Domain\Tools\VpProjectRequirements;

it('resolves locked catalog workspace alias and prerelease versions without a registry', function (string $spec, string $locked, string $expected): void {
    $configuration = [
        'package' => ['devDependencies' => ['vite-plus' => $spec], 'packageManager' => 'pnpm@10.17.0', 'engines' => ['node' => '>=22']],
        '.node-version' => '24.22.0',
        'locks' => ['pnpm-lock.yaml' => "lockfileVersion: '9.0'\nimporters:\n  .:\n    devDependencies:\n      vite-plus:\n        specifier: '{$spec}'\n        version: '{$locked}'\n"],
        'workspaces' => ['link:packages/vite-plus' => '0.3.3'],
    ];
    $environment = (new VpProjectRequirements)->resolve($configuration, '/opt/orbit/vite-plus');
    expect($environment->vpVersion)->toBe($expected)->and($environment->nodeVersion)->toBe('24.22.0');
})->with([
    ['catalog:', '0.3.3', '0.3.3'], ['catalog:build', '0.3.3(peer@1.0.0)', '0.3.3'],
    ['workspace:^', 'workspace:0.3.3', '0.3.3'], ['workspace:*', 'link:packages/vite-plus', '0.3.3'],
    ['npm:vite-plus@^0.3.0', 'vite-plus@0.3.3', '0.3.3'], ['^0.4.0-beta.1', '0.4.0-beta.2', '0.4.0-beta.2'],
]);

it('resolves npm aliases by locked dependency name and exact package manager metadata', function (): void {
    $environment = (new VpProjectRequirements)->resolve([
        'package' => ['devDependencies' => ['build-tools' => 'npm:vite-plus@^0.3.0'], 'engines' => ['node' => '24.22.0']],
        'locks' => ['package-lock.json' => json_encode(['npmVersion' => '11.12.0', 'packages' => ['node_modules/build-tools' => ['version' => '0.3.3', 'engines' => ['node' => '>=24']]]], JSON_THROW_ON_ERROR)],
    ], '/opt/orbit/vite-plus');
    expect($environment->vpVersion)->toBe('0.3.3')->and($environment->packageManager)->toBe('npm')->and($environment->packageManagerVersion)->toBe('11.12.0');
});

it('resolves npm workspace links from locked workspace versions', function (): void {
    $environment = (new VpProjectRequirements)->resolve([
        'package' => ['devDependencies' => ['vite-plus' => 'workspace:*'], 'packageManager' => 'npm@11.12.0', 'engines' => ['node' => '>=24']], '.nvmrc' => 'v24.22.0',
        'locks' => ['package-lock.json' => '{"packages":{"node_modules/vite-plus":{"resolved":"packages/vite-plus","link":true},"packages/vite-plus":{"version":"0.3.3"}}}'],
    ], '/opt/orbit/vite-plus');
    expect($environment->vpVersion)->toBe('0.3.3');
});

it('fails closed for incompatible engines or a mismatched package manager', function (string $node, string $manager): void {
    expect(fn () => (new VpProjectRequirements)->resolve([
        'package' => ['devDependencies' => ['vite-plus' => '0.3.3'], 'packageManager' => $manager], '.node-version' => $node,
        'locks' => ['package-lock.json' => '{"packages":{"node_modules/vite-plus":{"version":"0.3.3","engines":{"node":">=24"}}}}'],
    ], '/opt/orbit/vite-plus'))->toThrow(InvalidArgumentException::class);
})->with([['22.18.0', 'npm@11.12.0'], ['lts/*', 'npm@11.12.0'], ['24.22.0', 'pnpm@10.17.0'], ['24.22.0', 'npm@latest']]);
