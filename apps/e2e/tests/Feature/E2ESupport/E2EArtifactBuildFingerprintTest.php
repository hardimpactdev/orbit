<?php

declare(strict_types=1);

use App\E2E\Support\E2EGatewayImageBuildInputs;
use App\E2E\Support\E2EProvisionFingerprint;
use App\E2E\Support\E2ETopologyKind;

it('uses one gateway image manifest that matches every host-context Docker COPY source', function (): void {
    $manifestPaths = E2EGatewayImageBuildInputs::paths(repo_path());
    $dockerfilePaths = gateway_image_docker_copy_sources(
        (string) file_get_contents(repo_path('docker/orbit-gateway/Dockerfile')),
    );

    expect($manifestPaths)
        ->toBe($dockerfilePaths)
        ->toContain('bin/install-orbit')
        ->toContain('VERSION')
        ->toContain('packages/core/resources/php-cli/artifact-catalog.json');
});

it('uses the gateway image manifest for staging and matching artifact fingerprints', function (): void {
    $command = (string) file_get_contents(app_path('Console/Commands/E2EPrepareTopologyCommand.php'));
    $provision = E2EProvisionFingerprint::fromRoot(
        repo_path(),
        E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
    );

    expect($command)
        ->toContain('E2EGatewayImageBuildInputs::stagingPaths()')
        ->not->toContain("['apps/gateway', 'packages/core', 'packages/sdk', 'docker/orbit-gateway']")
        ->not->toContain("['bin/install-orbit', 'VERSION']")->and($provision['fingerprints']['gateway_artifact'])->toBe(
            E2EGatewayImageBuildInputs::fingerprint(repo_path()),
        )->and($provision['inputs']['source']['gateway_artifact']['files'])->toHaveKeys([
            'bin/install-orbit',
            'VERSION',
            'packages/core/resources/php-cli/artifact-catalog.json',
        ]);
});

it('changes the gateway image fingerprint when a declared critical input changes', function (string $path): void {
    $root = make_temp_directory('gateway-image-input-fingerprint');
    $manifest =
        implode("\n", [
            'VERSION',
            'bin/install-orbit',
            'docker/orbit-gateway',
            'packages/core/resources/php-cli/artifact-catalog.json',
        ])."\n";

    try {
        foreach ([
            'VERSION' => '1.0.0',
            'bin/install-orbit' => '#!/usr/bin/env bash',
            E2EGatewayImageBuildInputs::ManifestPath => $manifest,
            'packages/core/resources/php-cli/artifact-catalog.json' => '{}',
        ] as $fixturePath => $contents) {
            $absolute = "{$root}/{$fixturePath}";

            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), recursive: true);
            }

            file_put_contents($absolute, $contents);
        }

        $before = E2EGatewayImageBuildInputs::fingerprint($root);
        file_put_contents(filename: "{$root}/{$path}", data: "changed\n");

        expect(E2EGatewayImageBuildInputs::fingerprint($root))->not->toBe($before);
    } finally {
        remove_directory($root);
    }
})->with([
    'installer' => 'bin/install-orbit',
    'version' => 'VERSION',
    'core artifact catalog' => 'packages/core/resources/php-cli/artifact-catalog.json',
]);

it('changes the fingerprint when an arbitrary file is added and removed under a declared directory', function (string $directory): void {
    $root = make_gateway_image_input_fixture([
        'packages/core/src/Core.php' => "<?php\n",
    ], ['packages/core/src']);
    $path = "{$root}/packages/core/src/{$directory}/Added.php";

    try {
        $before = E2EGatewayImageBuildInputs::fingerprint($root);
        mkdir(dirname($path), recursive: true);
        file_put_contents($path, data: "<?php\nreturn 'added';\n");
        $afterAddition = E2EGatewayImageBuildInputs::fingerprint($root);
        unlink($path);
        $afterRemoval = E2EGatewayImageBuildInputs::fingerprint($root);

        expect($afterAddition)
            ->not->toBe($before)->and($afterRemoval)
            ->not->toBe($afterAddition)->toBe($before);
    } finally {
        remove_directory($root);
    }
})->with([
    'storage-shaped path copied by Docker' => 'storage',
    'bootstrap cache-shaped path copied by Docker' => 'bootstrap/cache',
]);

it('does not apply a broader PHP ignore policy than the Docker build context', function (): void {
    $root = make_gateway_image_input_fixture([
        'packages/core/src/storage/Stored.php' => "<?php\n",
        'packages/core/src/bootstrap/cache/Cached.php' => "<?php\n",
    ], ['packages/core/src']);
    $dockerignore = (string) file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    try {
        $inventory = E2EGatewayImageBuildInputs::inventory($root);

        expect($dockerignore)
            ->not->toContain('**/storage')
            ->not->toContain('**/bootstrap/cache')->and($inventory['files'])->toHaveKeys([
                'packages/core/src/storage/Stored.php',
                'packages/core/src/bootstrap/cache/Cached.php',
            ]);
    } finally {
        remove_directory($root);
    }
});

it('rejects duplicate manifest aliases after canonical normalization', function (): void {
    $root = make_gateway_image_input_fixture([
        'VERSION' => '1.0.0',
    ], ['VERSION', './VERSION']);

    try {
        expect(fn (): array => E2EGatewayImageBuildInputs::paths($root))
            ->toThrow(RuntimeException::class, 'duplicate canonical path: VERSION');
    } finally {
        remove_directory($root);
    }
});

it('rejects declared symlinks that resolve outside the repository', function (): void {
    $root = make_gateway_image_input_fixture([], ['linked-input']);
    $outside = make_temp_directory('gateway-image-input-outside');
    file_put_contents("{$outside}/Outside.php", data: "<?php\n");
    symlink("{$outside}/Outside.php", "{$root}/linked-input");

    try {
        expect(fn (): array => E2EGatewayImageBuildInputs::paths($root))
            ->toThrow(RuntimeException::class, 'resolves outside the repository: linked-input');
    } finally {
        remove_directory($root);
        remove_directory($outside);
    }
});

it('rejects declared symlinks that alias an internal repository input', function (): void {
    $root = make_gateway_image_input_fixture([
        'VERSION' => '1.0.0',
    ], ['linked-input']);
    symlink('VERSION', "{$root}/linked-input");

    try {
        expect(fn (): array => E2EGatewayImageBuildInputs::paths($root))
            ->toThrow(RuntimeException::class, 'symbolic-link aliases are not allowed: linked-input');
    } finally {
        remove_directory($root);
    }
});

it('fails closed when a declared input is missing', function (): void {
    $root = make_gateway_image_input_fixture([], ['missing-input']);

    try {
        expect(fn (): array => E2EGatewayImageBuildInputs::paths($root))
            ->toThrow(RuntimeException::class, 'declared input does not exist: missing-input');
    } finally {
        remove_directory($root);
    }
});

it('fails closed when a declared input is unreadable', function (): void {
    $root = make_gateway_image_input_fixture([
        'private-input' => 'secret',
    ], ['private-input']);
    chmod("{$root}/private-input", permissions: 0o000);

    try {
        expect(fn (): array => E2EGatewayImageBuildInputs::paths($root))
            ->toThrow(RuntimeException::class, 'declared input is not readable: private-input');
    } finally {
        chmod("{$root}/private-input", permissions: 0o600);
        remove_directory($root);
    }
});

it('returns deterministic canonical staging paths contained in the repository', function (): void {
    $root = make_gateway_image_input_fixture([
        'VERSION' => '1.0.0',
        'packages/core/src/Core.php' => "<?php\n",
    ], [
        './packages/core/src/Core.php',
        'VERSION',
        'packages/core/src',
    ]);

    try {
        $first = E2EGatewayImageBuildInputs::stagingPaths($root);
        $second = E2EGatewayImageBuildInputs::stagingPaths($root);

        expect($first)
            ->toBe(['VERSION', 'packages/core/src'])
            ->toBe($second);

        foreach ($first as $path) {
            $resolved = realpath("{$root}/{$path}");

            expect($path)
                ->not->toStartWith('/')
                ->not->toContain('..')->and($resolved)->toBeString()->toStartWith(realpath($root).'/');
        }
    } finally {
        remove_directory($root);
    }
});

it('includes the SDK package in CLI and gateway artifact build fingerprints', function (): void {
    $source = file_get_contents(app_path('E2E/Support/E2EArtifactBuildFingerprint.php'));
    $gatewayInputs = E2EGatewayImageBuildInputs::paths(repo_path());

    expect($source)
        ->toContain("'packages/sdk'")
        ->toContain("'packages/sdk/vendor'")
        ->and($gatewayInputs)
        ->toContain('packages/sdk/composer.json')
        ->toContain('packages/sdk/composer.lock')
        ->toContain('packages/sdk/src');
});

it('does not fingerprint the removed gateway node-scripts packaging path', function (): void {
    $artifact = (string) file_get_contents(app_path('E2E/Support/E2EArtifactBuildFingerprint.php'));
    $provision = (string) file_get_contents(app_path('E2E/Support/E2EProvisionFingerprint.php'));

    expect($artifact)
        ->not->toContain('apps/gateway/resources/node-scripts')->and($provision)
        ->not->toContain('apps/gateway/resources/node-scripts');
});

/**
 * @return list<string>
 */
function gateway_image_docker_copy_sources(string $dockerfile): array
{
    $sources = [];
    $lines = preg_split('/\R/', $dockerfile);

    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        if (preg_match('/^COPY\s+(.+)$/', trim($line), $matches) !== 1) {
            continue;
        }

        if (str_contains($matches[1], '--from=')) {
            continue;
        }

        $tokens = preg_split('/\s+/', $matches[1]);

        if ($tokens === false) {
            continue;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn (string $copyArgument): bool => ! str_starts_with($copyArgument, '--'),
        ));
        array_pop($tokens);

        array_push($sources, ...$tokens);
    }

    $sources = array_values(array_unique($sources));
    sort($sources);

    return $sources;
}

/**
 * @param  array<string, string>  $files
 * @param  list<string>  $manifestPaths
 */
function make_gateway_image_input_fixture(array $files, array $manifestPaths): string
{
    $root = make_temp_directory('gateway-image-input-contract');
    $files[E2EGatewayImageBuildInputs::ManifestPath] = implode("\n", $manifestPaths)."\n";

    foreach ($files as $path => $contents) {
        $absolute = "{$root}/{$path}";

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), recursive: true);
        }

        file_put_contents($absolute, $contents);
    }

    return $root;
}
