<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function prepareReleaseWorkflowPackage(string $package, string $version, string $output): void
{
    $process = new Process([
        PHP_BINARY,
        repo_path('bin/orbit-prepare-release-package'),
        "--package={$package}",
        "--version={$version}",
        "--output={$output}",
    ], repo_path());
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
}

/**
 * Extract the exact inline PHP verifier embedded in the release workflow so it
 * can be executed against fixtures. The workflow runs it as a `php <<'PHP'`
 * heredoc whose leading indentation is stripped by the YAML block scalar.
 */
function releaseWorkflowVerificationScript(): string
{
    $workflow = (string) file_get_contents(repo_path('.github/workflows/orbit-release.yml'));
    $capturing = false;
    $php = [];

    foreach (explode("\n", $workflow) as $line) {
        if (! $capturing) {
            if (str_contains($line, "php <<'PHP'")) {
                $capturing = true;
            }

            continue;
        }

        if (trim($line) === 'PHP') {
            break;
        }

        $php[] = preg_replace('/^ {10}/', '', $line);
    }

    expect($php)->not->toBeEmpty();

    return implode("\n", $php);
}

/**
 * Build a promotion asset directory whose manifest, CLI binaries, and websocket
 * role image archive are internally consistent for the given version.
 */
function releaseWorkflowAssetDir(string $version = '1.2.3'): string
{
    $root = sys_get_temp_dir().'/orbit-release-verify-'.bin2hex(random_bytes(6));

    mkdir($root, 0o700, true);
    file_put_contents("{$root}/orbit-linux-x64", 'linux-binary-'.bin2hex(random_bytes(4)));
    file_put_contents("{$root}/orbit-macos-arm64", 'mac-binary-'.bin2hex(random_bytes(4)));
    file_put_contents("{$root}/orbit-reverb-linux-amd64.tar", 'reverb-image-'.bin2hex(random_bytes(4)));

    $process = new Process([
        PHP_BINARY,
        repo_path('bin/orbit-release-manifest'),
        "--version={$version}",
        '--source=github-release',
        "--gateway-image=ghcr.io/hardimpactdev/orbit-gateway:{$version}@sha256:".str_repeat('a', times: 64),
        '--repository=hardimpactdev/orbit',
        "--cli-artifact=linux-amd64=orbit-linux-x64={$root}/orbit-linux-x64",
        "--cli-artifact=darwin-arm64=orbit-macos-arm64={$root}/orbit-macos-arm64",
        '--role-image=orbit-caddy=caddy:2-alpine',
        "--role-image=orbit-websocket=ghcr.io/hardimpactdev/orbit-reverb:{$version}@sha256:".str_repeat('d', times: 64),
        "--role-image-artifact=orbit-websocket=orbit-reverb-linux-amd64.tar={$root}/orbit-reverb-linux-amd64.tar",
        "--output={$root}/orbit-release-manifest.json",
    ], repo_path());
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return $root;
}

/**
 * @return array{process: Process, output: string}
 */
function runReleaseWorkflowVerification(
    string $assetDir,
    string $version = '1.2.3',
    string $repository = 'hardimpactdev/orbit',
): array {
    $script = sys_get_temp_dir().'/orbit-release-verify-script-'.bin2hex(random_bytes(6)).'.php';
    $output = sys_get_temp_dir().'/orbit-release-verify-output-'.bin2hex(random_bytes(6));

    file_put_contents($script, releaseWorkflowVerificationScript());
    file_put_contents($output, '');

    $process = new Process([PHP_BINARY, $script], repo_path(), [
        'ASSET_DIR' => $assetDir,
        'VERSION' => $version,
        'GITHUB_REPOSITORY' => $repository,
        'GITHUB_OUTPUT' => $output,
        'PATH' => (string) getenv('PATH'),
    ]);
    $process->run();

    return ['process' => $process, 'output' => $output];
}

/**
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
 */
function mutateReleaseWorkflowManifest(string $assetDir, callable $mutator): void
{
    $path = "{$assetDir}/orbit-release-manifest.json";
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    file_put_contents(
        $path,
        json_encode($mutator($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
}

it('promotes prebuilt cli artifacts gateway image and release manifest on GitHub releases', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        ->toContain('name: Orbit Release')
        ->toContain('workflow_dispatch:')
        ->toContain('types: [published]')
        ->toContain('contents: write')
        ->toContain('packages: write')
        ->toContain('bin/orbit-version')
        ->toContain('Release tag')
        ->toContain('does not match VERSION')
        ->toContain('Download prebuilt release assets')
        ->toContain('gh release download "$TAG"')
        ->toContain('Verify promoted release manifest')
        ->toContain('source')
        ->toContain('github-release')
        ->toContain('hash_file')
        ->toContain("preg_match('/^[0-9a-f]{64}$/D'")
        ->toContain('docker/setup-buildx-action@v3')
        ->toContain('Log in to GHCR')
        ->toContain('Promote canonical gateway image version tag')
        ->toContain('docker buildx imagetools create')
        ->toContain('--prefer-index=false')
        ->toContain('--tag "$promoted_ref"')
        ->toContain('GATEWAY_DIGEST: ${{ steps.manifest.outputs.gateway_digest }}')
        ->toContain('accepted_ref="${REGISTRY}/${IMAGE_NAME}@${GATEWAY_DIGEST}"')
        ->toContain('promoted_ref="${REGISTRY}/${IMAGE_NAME}:${VERSION}"')
        ->toContain('inspect_output="$(docker buildx imagetools inspect "$promoted_ref")"')
        ->toContain('actual_digest=')
        ->toContain('awk \'/^Digest:/ { print $2; exit }\'')
        ->toContain('if [ "$actual_digest" != "$GATEWAY_DIGEST" ]; then')
        ->toContain('Promoted gateway digest is [${actual_digest:-missing}], expected [$GATEWAY_DIGEST].')
        ->toContain('Promoted gateway digest')
        ->toContain('Verify accepted gateway image is pullable')
        ->toContain('Verify accepted gateway image bakes the release version')
        ->toContain('bin/orbit-prepare-release-package --package="$package"')
        ->toContain('publish_split core hardimpactdev/orbit-core')
        ->toContain('publish_split cli hardimpactdev/orbit-cli')
        ->toContain('publish_split gateway hardimpactdev/orbit-gateway')
        ->toContain('hardimpactdev/orbit-core')
        ->toContain('hardimpactdev/orbit-cli')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('rm -rf "$output/node_modules"')
        ->toContain('ORBIT_RELEASE_TOKEN')
        ->toContain('PACKAGIST_USERNAME')
        ->toContain('PACKAGIST_TOKEN')
        ->toContain('ghcr.io')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('orbit-release-manifest.json')
        ->toContain('orbit-linux-x64')
        ->toContain('orbit-macos-arm64')
        // TypeScript SDK is independently versioned and published from its
        // package repository OIDC workflow, not monorepo orbit-release.yml.
        ->not->toContain('prepare_and_verify_sdk_typescript')
        ->not->toContain('publish_sdk_typescript_npm')
        ->not->toContain('publish_sdk_typescript_split')
        ->not->toContain('npm publish --access public --provenance')
        ->not->toContain('NPM_TOKEN')
        ->not->toContain('NODE_AUTH_TOKEN')
        ->not->toContain('actions/setup-node@v6')
        ->not->toContain('actions/setup-node@v4')
        ->not->toContain('@orbit/sdk-typescript')
        ->not->toContain('docker buildx build')
        ->not->toContain('--push')
        ->not->toContain('--metadata-file')
        ->not->toContain('containerimage.digest')
        ->not->toContain('bin/orbit-build-cli-binary mac arm')
        ->not->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain('bin/orbit-release-manifest')
        ->not->toContain('gh release upload')
        ->not->toContain('--clobber')
        ->not->toContain('cp apps/cli/builds/dist/linux/linux-x64 orbit-linux-x64')
        ->not->toContain('cp apps/cli/builds/dist/mac/mac-arm orbit-macos-arm64')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('#orbit-linux-x64')
        ->not->toContain('#orbit-macos-arm64')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64')
        ->not->toContain('orbit'.'-runtime');

    expect($workflow)->toMatch(
        '/Verify promoted release manifest[\s\S]*Verify accepted gateway image is pullable[\s\S]*Verify accepted gateway image bakes the release version[\s\S]*Promote canonical gateway image version tag/',
    );

    expect($workflow)->toMatch(
        '/docker buildx imagetools create[\s\S]*docker buildx imagetools inspect "\$promoted_ref"[\s\S]*actual_digest=[\s\S]*if \[ "\$actual_digest" != "\$GATEWAY_DIGEST" \]; then[\s\S]*Publish verified GitHub release/',
    );

    expect($workflow)->toMatch(
        '/if \[ "\$actual_digest" != "\$GATEWAY_DIGEST" \]; then\n\s+echo "Promoted gateway digest is \[\$\{actual_digest:-missing\}\], expected \[\$GATEWAY_DIGEST\]\." >&2\n\s+exit 1\n\s+fi/',
    );
});

it('pushes the release git tag before creating the draft so checkout can resolve the ref', function (): void {
    $skill = (string) file_get_contents(repo_path('.agents/skills/release/SKILL.md'));
    $workflow = (string) file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        ->toContain('ref: ${{ inputs.tag || github.event.release.tag_name }}')
        ->not->toContain("\n  push:")
        ->not->toContain('tags:');

    $skillText = (string) preg_replace('/\s+/', ' ', $skill);

    expect($skillText)
        ->toContain('does not create a git tag')
        ->toContain('no tag-push trigger');

    preg_match_all('/```bash\s*\n(.*?)\n\s*```/s', $skill, $blocks);

    $recipe = '';
    foreach ($blocks[1] as $block) {
        if (str_contains($block, 'gh release create "v${version}"')) {
            $recipe = $block;
            break;
        }
    }

    expect($recipe)
        ->not
        ->toBe('')
        ->and($recipe)
        ->toContain('git tag "v${version}"')
        ->toContain('git push origin "v${version}"')
        ->toContain('gh release create "v${version}"');

    $tagPos = strpos($recipe, 'git tag "v${version}"');
    $pushPos = strpos($recipe, 'git push origin "v${version}"');
    $createPos = strpos($recipe, 'gh release create "v${version}"');

    expect($tagPos)
        ->not->toBeFalse()->and($pushPos)
        ->not->toBeFalse()->and($createPos)
        ->not->toBeFalse()->and($tagPos)->toBeLessThan($pushPos)->and($pushPos)->toBeLessThan($createPos);
});

it('keeps the GitHub release unpublished until draft asset verification succeeds', function (): void {
    $workflow = (string) file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('description: Draft release tag to verify and publish')
        ->toContain('inputs.tag || github.event.release.tag_name')
        ->toContain('types: [published]')
        ->toContain('Publish verified GitHub release')
        ->toContain('gh release edit "$TAG" --draft=false --repo "$GITHUB_REPOSITORY"')
        ->toContain('Keep failed release unpublished')
        ->toContain('if: failure()')
        ->toContain('gh release edit "$TAG" --draft --repo "$GITHUB_REPOSITORY"')
        ->toContain('--tag "$promoted_ref"')
        ->not->toContain('--tag "${REGISTRY}/${IMAGE_NAME}:${VERSION}"');

    // Draft verification, split-package promotion, and the version-tag
    // promotion must finish before the GitHub release becomes public. The
    // failure path converts a premature published release back to a draft.
    expect($workflow)->toMatch(
        '/Verify promoted release manifest[\s\S]*Publish split package repositories[\s\S]*Promote canonical gateway image version tag[\s\S]*Publish verified GitHub release[\s\S]*Keep failed release unpublished/',
    );
});

it('promotes the GHCR version tag only after the accepted image verifies and split packages publish', function (): void {
    $workflow = (string) file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        ->toContain('Verify accepted gateway image is pullable')
        ->toContain('Verify accepted gateway image bakes the release version')
        ->toContain('gateway_digest=sha256:{$gatewayDigest}')
        ->toContain('GATEWAY_DIGEST: ${{ steps.manifest.outputs.gateway_digest }}')
        ->toContain('docker pull "${REGISTRY}/${IMAGE_NAME}@${GATEWAY_DIGEST}"')
        ->toContain('accepted_ref="${REGISTRY}/${IMAGE_NAME}@${GATEWAY_DIGEST}"')
        ->toContain('docker run --rm --entrypoint cat "$accepted_ref" /srv/orbit/VERSION')
        // Nothing before promotion may depend on the version tag existing:
        // every image check resolves the accepted digest directly.
        ->not->toContain('GATEWAY_REF')
        ->not->toContain('gateway_ref=');

    // Both image checks and split publishing precede the only tag mutation,
    // which is the last step before the GitHub release goes public.
    expect($workflow)->toMatch(
        '/Verify promoted release manifest[\s\S]*Verify accepted gateway image is pullable[\s\S]*Verify accepted gateway image bakes the release version[\s\S]*Publish split package repositories[\s\S]*Promote canonical gateway image version tag[\s\S]*Publish verified GitHub release[\s\S]*Keep failed release unpublished/',
    );

    // Promotion carbon-copies the accepted digest onto the version tag; the
    // tag need not exist yet, so the source is the bare digest reference.
    expect($workflow)->toContain("--tag \"\$promoted_ref\" \\\n            \"\$accepted_ref\"");

    // The workflow is the only place that moves the version tag.
    expect(substr_count($workflow, needle: 'docker buildx imagetools create'))->toBe(1);
});

it('keeps the operator release recipe from moving the GHCR version tag before the workflow verifies', function (): void {
    $skill = (string) file_get_contents(repo_path('.agents/skills/release/SKILL.md'));
    $skillText = (string) preg_replace('/\s+/', replacement: ' ', subject: $skill);

    expect($skill)
        // The operator never promotes the version tag; the workflow owns it.
        ->not->toContain('docker buildx imagetools create')
        ->not->toContain('release_image="ghcr.io/hardimpactdev/orbit-gateway:${version}"')
        // Pre-publication verify binds the accepted candidate image to the
        // recorded digest before the draft is dispatched.
        ->toContain('bin/orbit-release-candidate verify --release-image="$candidate_image"')
        // The public verify still proves the workflow-promoted tag resolves
        // to the accepted digest.
        ->toContain(
            'bin/orbit-release-candidate verify --release-image="ghcr.io/hardimpactdev/orbit-gateway:${version}"',
        );

    expect($skillText)
        ->toContain(
            'The release workflow promotes the accepted gateway image digest to the final `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>` package tag only after the draft assets and the digest-pinned image verify and the split package repositories publish, immediately before it publishes the GitHub release.',
        )
        ->toContain(
            'The operator never moves the version tag, so a failed verification leaves the GHCR version tag unmoved.',
        )
        ->toContain(
            'A failed verification leaves the draft unpublished and the GHCR version tag unmoved, and converts a premature published release back to a draft.',
        );

    // The candidate verify precedes manifest generation and the draft dispatch.
    $verifyPos = strpos($skill, needle: 'bin/orbit-release-candidate verify --release-image="$candidate_image"');
    $manifestPos = strpos($skill, needle: '--source=github-release');
    $dispatchPos = strpos($skill, needle: 'gh workflow run orbit-release.yml');

    expect($verifyPos)
        ->toBeInt()
        ->and($manifestPos)
        ->toBeInt()
        ->and($dispatchPos)
        ->toBeInt()
        ->and($verifyPos)
        ->toBeLessThan($manifestPos)
        ->and($manifestPos)
        ->toBeLessThan($dispatchPos);

    $techStack = (string) preg_replace(
        '/\s+/',
        replacement: ' ',
        subject: (string) file_get_contents(repo_path('apps/docs/content/tech-stack.md')),
    );

    expect($techStack)
        ->toContain(
            'promotes the verified gateway digest to the `ghcr.io/hardimpactdev/orbit-gateway:<version>` package tag',
        )
        ->toContain(
            'A failed verification leaves the draft unpublished and the GHCR version tag unmoved, and converts a premature published release back to a draft.',
        );

    $decisions = (string) file_get_contents(repo_path('PRODUCT_DECISIONS.md'));

    expect($decisions)->toMatch(
        '/^- 2026-08-20 — [^\n]*ghcr\.io\/hardimpactdev\/orbit-gateway:<VERSION>[^\n]*\(solo todo #225\)$/m',
    );
});

it('requires publishes and hash-verifies the websocket role image archive during promotion', function (): void {
    $workflow = (string) file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        // The reverb role image archive is downloaded and required to be present.
        ->toContain('--pattern orbit-reverb-linux-amd64.tar')
        ->toContain('test -s "$asset_dir/orbit-reverb-linux-amd64.tar"')
        // The promoted manifest must expose the credential-free websocket artifact.
        ->toContain("\$manifest['role_image_artifacts']['orbit-websocket']")
        ->toContain('Release manifest must publish role_image_artifacts.orbit-websocket')
        ->toContain(
            '$expectedReverbUrl = "https://github.com/{$repository}/releases/download/v{$version}/{$reverbAsset}";',
        )
        ->toContain('Release manifest role_image_artifacts.orbit-websocket URL must point at')
        ->toContain('Release manifest websocket role image archive')
        ->toContain("hash_file('sha256', \$reverbPath)")
        ->toContain(
            'Release manifest role_image_artifacts.orbit-websocket sha256 does not match the promoted archive.',
        );

    // The archive download precedes manifest verification which precedes gateway promotion.
    expect($workflow)->toMatch(
        '/--pattern orbit-reverb-linux-amd64\.tar[\s\S]*Verify promoted release manifest[\s\S]*role_image_artifacts.*orbit-websocket[\s\S]*Promote canonical gateway image version tag/',
    );
});

it('accepts a promotion manifest whose websocket archive url and checksum match', function (): void {
    $assetDir = releaseWorkflowAssetDir();

    try {
        ['process' => $process, 'output' => $output] = runReleaseWorkflowVerification($assetDir);

        expect($process->getExitCode())
            ->toBe(0, $process->getErrorOutput())
            ->and((string) file_get_contents($output))
            ->toContain('gateway_digest=sha256:'.str_repeat('a', times: 64));
    } finally {
        new Process(['rm', '-rf', $assetDir])->run();
    }
});

it('fails promotion verification when the websocket archive is missing', function (): void {
    $assetDir = releaseWorkflowAssetDir();

    try {
        unlink("{$assetDir}/orbit-reverb-linux-amd64.tar");

        ['process' => $process] = runReleaseWorkflowVerification($assetDir);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('websocket role image archive');
    } finally {
        new Process(['rm', '-rf', $assetDir])->run();
    }
});

it('fails promotion verification when the websocket artifact entry is absent', function (): void {
    $assetDir = releaseWorkflowAssetDir();

    try {
        mutateReleaseWorkflowManifest($assetDir, function (array $manifest): array {
            unset($manifest['role_image_artifacts']);

            return $manifest;
        });

        ['process' => $process] = runReleaseWorkflowVerification($assetDir);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('role_image_artifacts.orbit-websocket');
    } finally {
        new Process(['rm', '-rf', $assetDir])->run();
    }
});

it('fails promotion verification when the websocket artifact url is wrong', function (): void {
    $assetDir = releaseWorkflowAssetDir();

    try {
        mutateReleaseWorkflowManifest($assetDir, function (array $manifest): array {
            $manifest['role_image_artifacts']['orbit-websocket']['url'] = 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/wrong-asset.tar';

            return $manifest;
        });

        ['process' => $process] = runReleaseWorkflowVerification($assetDir);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('URL must point at');
    } finally {
        new Process(['rm', '-rf', $assetDir])->run();
    }
});

it('fails promotion verification when the websocket archive checksum mismatches', function (): void {
    $assetDir = releaseWorkflowAssetDir();

    try {
        // Rewrite the archive bytes after the manifest recorded the original hash.
        file_put_contents("{$assetDir}/orbit-reverb-linux-amd64.tar", 'tampered-reverb-image');

        ['process' => $process] = runReleaseWorkflowVerification($assetDir);

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('sha256 does not match the promoted archive');
    } finally {
        new Process(['rm', '-rf', $assetDir])->run();
    }
});

it('keeps the TypeScript SDK independently versioned with Craft-style package-repo OIDC publish', function (): void {
    $rootVersion = trim((string) file_get_contents(repo_path('VERSION')));
    /** @var array<string, mixed> $sourcePackage */
    $sourcePackage = json_decode(
        (string) file_get_contents(repo_path('packages/sdk-typescript/package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $packageVersion = is_string($sourcePackage['version'] ?? null) ? $sourcePackage['version'] : '';

    expect($packageVersion)
        ->toBe('0.3.0')
        ->and($packageVersion)
        ->not
        ->toBe($rootVersion)
        ->and($sourcePackage['private'] ?? false)
        ->toBeTrue();

    $publishWorkflow = (string) file_get_contents(
        repo_path('packages/sdk-typescript/.github/workflows/publish.yml'),
    );

    expect($publishWorkflow)
        ->toContain('name: Publish package to npm')
        ->toContain('types: [published]')
        ->toContain('workflow_dispatch:')
        ->toContain('contents: read')
        ->toContain('id-token: write')
        ->toContain('actions/setup-node@v6')
        ->toContain('node-version: "24"')
        ->toContain('package-manager-cache: false')
        ->toContain('registry-url: "https://registry.npmjs.org"')
        ->toContain('npm ci')
        ->toContain('npm test')
        ->toContain('npm run build')
        ->toContain('npm version "${GITHUB_REF_NAME#v}" --no-git-tag-version --allow-same-version')
        ->toContain('npm publish --provenance --access public')
        // Steady-state release path is token-free OIDC.
        ->toContain('github.event_name == \'release\'')
        ->toContain('Publish release via OIDC Trusted Publisher')
        // One-time bootstrap is fail-closed and split-repo secret only.
        ->toContain('github.event_name == \'workflow_dispatch\'')
        ->toContain('Guard one-time bootstrap publish')
        ->toContain('Bootstrap refuses version')
        ->toContain('scripts/npm-bootstrap-registry-absent.sh')
        ->toContain('only exact 0.1.0 is allowed')
        ->toContain('confirmed npm E404 absence')
        ->toContain('secrets.NPM_BOOTSTRAP_TOKEN')
        ->toContain('Publish bootstrap with short-lived split-repo credential')
        // Monorepo durable secret name must not appear (bootstrap uses NPM_BOOTSTRAP_TOKEN only).
        ->not->toContain('secrets.NPM_TOKEN')
        ->not->toContain('${{ secrets.NPM_TOKEN }}');

    $registryGuard = (string) file_get_contents(
        repo_path('packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh'),
    );

    expect(repo_path('packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh'))
        ->toBeFile()
        ->and(is_executable(repo_path('packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh')))
        ->toBeTrue()
        ->and($registryGuard)
        ->toContain('already exists on the registry; bootstrap is one-time only')
        ->toContain('non-E404 error; bootstrap refuses to proceed')
        ->toContain('code E404');

    // Release publish step must not inject a long-lived monorepo token.
    expect($publishWorkflow)
        ->toMatch(
            '/Publish release via OIDC Trusted Publisher[\s\S]*?if: github\.event_name == \'release\'[\s\S]*?run: npm publish --provenance --access public/',
        );

    $rootWorkflow = (string) file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($rootWorkflow)
        ->not->toContain('publish_sdk_typescript_npm')
        ->not->toContain('npm publish --access public --provenance')
        ->not->toContain('NPM_BOOTSTRAP_TOKEN')
        ->not->toContain('NPM_TOKEN');
});

it('executes npm bootstrap registry absence checks with fail-closed E404 semantics', function (): void {
    $script = repo_path('packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh');
    $mockRoot = sys_get_temp_dir().'/orbit-npm-bootstrap-mock-'.bin2hex(random_bytes(6));
    $mockBin = $mockRoot.'/bin';

    mkdir($mockBin, 0755, true);

    $npmStub = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail

        mode="${ORBIT_NPM_VIEW_MODE:-}"
        spec="${2:-}"

        case "$mode" in
          e404)
            echo "npm error code E404" >&2
            echo "npm error 404 Not Found - GET https://registry.npmjs.org/${spec} - Not found" >&2
            exit 1
            ;;
          exists)
            echo "0.1.0"
            exit 0
            ;;
          network)
            echo "npm error code ENOTFOUND" >&2
            echo "npm error network request failed" >&2
            exit 1
            ;;
          auth)
            echo "npm error code E401" >&2
            echo "npm error 401 Unauthorized" >&2
            exit 1
            ;;
          *)
            echo "unknown ORBIT_NPM_VIEW_MODE [$mode]" >&2
            exit 2
            ;;
        esac
        BASH;

    file_put_contents($mockBin.'/npm', $npmStub);
    chmod($mockBin.'/npm', 0755);

    $run = static function (string $mode, string $spec) use ($script, $mockBin): Process {
        $process = new Process(
            ['bash', $script, $spec],
            null,
            [
                'PATH' => $mockBin.PATH_SEPARATOR.getenv('PATH'),
                'ORBIT_NPM_VIEW_MODE' => $mode,
            ],
        );
        $process->run();

        return $process;
    };

    try {
        $absent = $run('e404', '@hardimpactdev/orbit-sdk-typescript');
        expect($absent->getExitCode())
            ->toBe(0, $absent->getErrorOutput().$absent->getOutput());

        $absentVersion = $run('e404', '@hardimpactdev/orbit-sdk-typescript@0.1.0');
        expect($absentVersion->getExitCode())
            ->toBe(0, $absentVersion->getErrorOutput().$absentVersion->getOutput());

        $exists = $run('exists', '@hardimpactdev/orbit-sdk-typescript');
        expect($exists->getExitCode())
            ->not
            ->toBe(0)
            ->and($exists->getErrorOutput())
            ->toContain('already exists on the registry; bootstrap is one-time only');

        $network = $run('network', '@hardimpactdev/orbit-sdk-typescript');
        expect($network->getExitCode())
            ->not
            ->toBe(0)
            ->and($network->getErrorOutput())
            ->toContain('non-E404 error; bootstrap refuses to proceed')
            ->toContain('ENOTFOUND');

        $auth = $run('auth', '@hardimpactdev/orbit-sdk-typescript@0.1.0');
        expect($auth->getExitCode())
            ->not
            ->toBe(0)
            ->and($auth->getErrorOutput())
            ->toContain('non-E404 error; bootstrap refuses to proceed')
            ->toContain('E401');
    } finally {
        File::deleteDirectory($mockRoot);
    }
});

it('prepares a local durable sdk-typescript package with independent package.json version', function (): void {
    $rootVersion = trim((string) file_get_contents(repo_path('VERSION')));
    /** @var array<string, mixed> $sourcePackage */
    $sourcePackage = json_decode(
        (string) file_get_contents(repo_path('packages/sdk-typescript/package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $packageVersion = is_string($sourcePackage['version'] ?? null) ? $sourcePackage['version'] : '';
    $output = sys_get_temp_dir().'/orbit-sdk-typescript-prepare-'.uniqid('', true);

    prepareReleaseWorkflowPackage('sdk-typescript', $packageVersion, $output);

    expect($output.'/package.json')
        ->toBeFile()
        ->and($output.'/README.md')
        ->toBeFile()
        ->and($output.'/openapi/public-gateway-openapi.json')
        ->toBeFile()
        ->and($output.'/package-lock.json')
        ->toBeFile()
        ->and($output.'/.github/workflows/publish.yml')
        ->toBeFile()
        ->and(is_dir($output.'/node_modules'))
        ->toBeFalse();

    /** @var array<string, mixed> $packageJson */
    $packageJson = json_decode((string) file_get_contents($output.'/package.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($packageJson['name'] ?? null)
        ->toBe('@hardimpactdev/orbit-sdk-typescript')
        ->and($packageJson['version'] ?? null)
        ->toBe($packageVersion)
        ->and($packageJson['version'] ?? null)
        ->not->toBe($rootVersion)->and($packageJson['private'] ?? true)->toBeFalse()->and(
            $packageJson['license'] ?? null,
        )->toBe('MIT')->and($packageJson['publishConfig'] ?? null)->toMatchArray([
            'access' => 'public',
            'registry' => 'https://registry.npmjs.org/',
        ])->and(data_get($packageJson, 'repository.url'))->toBe(
            'https://github.com/hardimpactdev/orbit-sdk-typescript.git',
        )->and(data_get($packageJson, 'repository.directory'))->toBeNull()->and($packageJson['homepage'] ?? null)->toBe(
            'https://github.com/hardimpactdev/orbit-sdk-typescript',
        )->and($packageJson['files'] ?? null)->toBe([
            'dist',
            'openapi/public-gateway-openapi.json',
            'README.md',
        ])->and($packageJson['scripts'] ?? null)->toBe([
            'build' => 'tsc -p tsconfig.build.json',
            'pack:dry-run' => 'npm pack --dry-run',
            'test' => 'npm run typecheck && npm run test:runtime',
            'test:runtime' => 'node --experimental-strip-types --test tests/process-stream.test.ts tests/client.test.ts',
            'typecheck' => 'tsc --noEmit',
        ])->and($packageJson['scripts'] ?? [])
        ->not->toHaveKeys([
            'generate',
            'generate:openapi',
            'generate:types',
        ])->and(json_encode($packageJson))
        ->not->toContain('@orbit/sdk-typescript');

    $sourcePackageJson = (string) file_get_contents(repo_path('packages/sdk-typescript/package.json'));
    $helper = (string) file_get_contents(repo_path('bin/orbit-prepare-release-package'));

    expect($sourcePackageJson)
        ->toContain('"name": "@hardimpactdev/orbit-sdk-typescript"')
        ->toContain('"private": true')
        ->not->toContain('@orbit/sdk-typescript')->and($helper)->toContain(
            "'sdk-typescript' => 'packages/sdk-typescript'",
        )->toContain('@hardimpactdev/orbit-sdk-typescript')->toContain('rewrite_npm_package_json')->toContain(
            'rewrite_npm_package_lock',
        )->toContain('read_npm_package_version')
        ->not->toContain('@orbit/sdk-typescript');

    File::deleteDirectory($output);
});

it('stamps prepared sdk-typescript package-lock identity to the package version not root VERSION', function (): void {
    $rootVersion = trim((string) file_get_contents(repo_path('VERSION')));
    /** @var array<string, mixed> $sourcePackage */
    $sourcePackage = json_decode(
        (string) file_get_contents(repo_path('packages/sdk-typescript/package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $packageVersion = is_string($sourcePackage['version'] ?? null) ? $sourcePackage['version'] : '';
    $output = sys_get_temp_dir().'/orbit-sdk-typescript-lock-'.uniqid('', true);

    prepareReleaseWorkflowPackage('sdk-typescript', $packageVersion, $output);

    /** @var array<string, mixed> $packageLock */
    $packageLock = json_decode(
        (string) file_get_contents($output.'/package-lock.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $lockRoot = is_array($packageLock['packages'][''] ?? null) ? $packageLock['packages'][''] : [];

    expect($packageLock['name'] ?? null)
        ->toBe('@hardimpactdev/orbit-sdk-typescript')
        ->and($packageLock['version'] ?? null)
        ->toBe($packageVersion)
        ->and($packageLock['version'] ?? null)
        ->not
        ->toBe($rootVersion)
        ->and($lockRoot['name'] ?? null)
        ->toBe('@hardimpactdev/orbit-sdk-typescript')
        ->and($lockRoot['version'] ?? null)
        ->toBe($packageVersion);

    File::deleteDirectory($output);
});

it('rejects preparing sdk-typescript with a version that is not package.json', function (): void {
    $output = sys_get_temp_dir().'/orbit-sdk-typescript-bad-version-'.uniqid('', true);
    $process = new Process([
        PHP_BINARY,
        repo_path('bin/orbit-prepare-release-package'),
        '--package=sdk-typescript',
        '--version=9.9.9',
        "--output={$output}",
    ], repo_path());
    $process->run();

    expect($process->getExitCode())
        ->not
        ->toBe(0)
        ->and($process->getErrorOutput())
        ->toContain('must match packages/sdk-typescript/package.json version');

    if (is_dir($output)) {
        File::deleteDirectory($output);
    }
});

it('builds cli binary workflows through the shared no-dev compressed phar helper', function (): void {
    $binaryWorkflow = file_get_contents(repo_path('.github/workflows/orbit-cli-binary.yml'));
    $releaseWorkflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($binaryWorkflow)
        ->toContain('zlib')
        ->toContain('bin/orbit-version')
        ->toContain('bin/orbit-build-cli-binary mac arm')
        ->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');

    expect($releaseWorkflow)
        ->toContain('zlib')
        ->toContain('bin/orbit-version')
        ->not->toContain('bin/orbit-build-cli-binary mac arm')
        ->not->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');
});

it('uses the root VERSION file as the single release version source', function (): void {
    $version = trim((string) file_get_contents(repo_path('VERSION')));
    $cliConfig = file_get_contents(repo_path('apps/cli/config/app.php'));
    $gatewayConfig = file_get_contents(repo_path('apps/gateway/config/app.php'));
    $gatewayWorkflow = file_get_contents(repo_path('.github/workflows/orbit-gateway-image.yml'));
    $binaryWorkflow = file_get_contents(repo_path('.github/workflows/orbit-cli-binary.yml'));
    $releaseWorkflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($version)
        ->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(config('app.version'))
        ->toBe($version)
        ->and(repo_path('bin/orbit-version'))
        ->toBeFile()
        ->and($cliConfig)
        ->toContain('VERSION')
        ->and($gatewayConfig)
        ->toContain('VERSION')
        ->and($cliConfig)
        ->not->toContain("'version' => '")->and($gatewayConfig)
        ->not->toContain("'version' => '")->and($gatewayWorkflow)
        ->not->toContain('bin/orbit-version')->and($binaryWorkflow)->toContain('bin/orbit-version')->and(
            $releaseWorkflow,
        )->toContain('bin/orbit-version');
});

it('builds local e2e cli binary artifacts through the shared helper', function (): void {
    $composer = json_decode((string) file_get_contents(repo_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $binaryScript = implode("\n", $composer['scripts']['test:e2e:binary']);
    $linuxBinaryScript = implode("\n", $composer['scripts']['test:e2e:binary:linux']);
    $dockerAcceptanceScript = implode("\n", $composer['scripts']['test:e2e:docker:binary-acceptance']);
    $combinedScripts = implode("\n", [$binaryScript, $linuxBinaryScript, $dockerAcceptanceScript]);

    expect($binaryScript)
        ->toContain('bin/orbit-build-cli-binary mac arm "$(bin/orbit-version)"')
        ->toContain('ORBIT_E2E_BINARY_PATH=$(pwd)/apps/cli/builds/dist/mac/mac-arm');

    expect($linuxBinaryScript)
        ->toContain('bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"');

    expect($dockerAcceptanceScript)
        ->toContain('bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"')
        ->toContain('ORBIT_E2E_BINARY_PATH_LINUX=$(pwd)/apps/cli/builds/dist/linux/linux-x64');

    expect($combinedScripts)
        ->not->toContain('rm -rf apps/cli/vendor/hardimpactdev/orbit-core')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');
});

it('documents Beast coordinated Mini native candidate builds over the LAN', function (): void {
    $skill = (string) file_get_contents(repo_path('.agents/skills/release/SKILL.md'));
    $techStack = (string) file_get_contents(repo_path('apps/docs/content/tech-stack.md'));
    $decisions = (string) file_get_contents(repo_path('PRODUCT_DECISIONS.md'));

    expect($skill)
        ->toContain('nckrtl@192.168.6.10')
        ->toContain('nckrtl@192.168.6.20')
        ->toContain('bin/orbit-release-build-worktree prepare')
        ->toContain('bin/orbit-build-native-release-assets')
        ->toContain('rsync')
        ->toContain('bin/orbit-release-candidate verify-native')
        ->toContain('bin/orbit-release-candidate build --native-assets=')
        ->toContain('mini_hostname="$(ssh -G nckrtl@192.168.6.10')
        ->toContain('mini_seen_beast="$(ssh -o BatchMode=yes nckrtl@192.168.6.10')
        ->toContain('verify the Beast address from Mini')
        ->toContain('Mini originates both')
        ->not->toContain('beast_hostname="$(ssh -G nckrtl@192.168.6.20')
        ->not->toContain('nckrtl@10.6.0.8')
        ->not->toContain('nckrtl@10.6.0.7');

    expect($techStack)
        ->toContain('Beast')
        ->toContain('Mini')
        ->toContain('Darwin ARM64')
        ->toContain('exact source commit')
        ->and($decisions)
        ->toContain('Beast coordinates release-candidate builds')
        ->toContain('192.168.6.10')
        ->toContain('192.168.6.20');
});

it('documents the compressed phar runtime extension contract', function (): void {
    $documents = [
        file_get_contents(repo_path('apps/docs/content/tech-stack.md')),
        file_get_contents(repo_path('apps/docs/content/domains/1_node/README.md')),
        file_get_contents(repo_path('apps/docs/content/domains/1_node/node-concepts.md')),
    ];

    foreach ($documents as $document) {
        expect($document)
            ->toContain('pdo_sqlite')
            ->toContain('phar')
            ->toContain('zlib');
    }
});

it('keeps gateway image build hygiene covered by dockerignore in the gateway image workflow', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-gateway-image.yml'));
    $dockerignore = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    expect($workflow)
        ->toContain('docker/orbit-gateway/Dockerfile')
        ->toContain('docker/orbit-gateway/Dockerfile.dockerignore');

    expect($dockerignore)
        ->toContain('**/.env')
        ->toContain('**/vendor')
        ->toContain('storage/logs')
        ->toContain('storage/*.sqlite')
        ->toContain('node_modules')
        ->toContain('.git');
});

it('prepares the gateway release split package manifest with the exact monorepo version', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-package-'.bin2hex(random_bytes(6));

    try {
        prepareReleaseWorkflowPackage('gateway', '1.2.3', "{$root}/gateway");

        $gateway = json_decode(
            (string) file_get_contents("{$root}/gateway/composer.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($gateway['name'])
            ->toBe('hardimpactdev/orbit-gateway')
            ->and($gateway['version'])
            ->toBe('1.2.3')
            ->and($gateway['require']['hardimpactdev/orbit-core'])
            ->toBe('1.2.3')
            ->and($gateway['require']['hardimpactdev/orbit-sdk-laravel'])
            ->toBe('1.2.3')
            ->and($gateway)
            ->not->toHaveKey('repositories')->and("{$root}/gateway/composer.lock")
            ->not->toBeFile()->and(is_executable("{$root}/gateway/artisan"))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

it('does not descend into skipped gateway runtime directories while packaging', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-package-'.bin2hex(random_bytes(6));
    $skippedDirectory = repo_path(
        'apps/gateway/storage/framework/testing/release-package-unreadable-'.bin2hex(random_bytes(6)),
    );

    try {
        mkdir($skippedDirectory, 0o000, true);

        prepareReleaseWorkflowPackage('gateway', '1.2.3', "{$root}/gateway");

        expect("{$root}/gateway/storage/framework/testing")->not->toBeDirectory();
    } finally {
        if (is_dir($skippedDirectory)) {
            chmod($skippedDirectory, 0o700);
            File::deleteDirectory($skippedDirectory);
        }

        File::deleteDirectory($root);
    }
});

it('prepares every release split package manifest with the exact monorepo version', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-package-'.bin2hex(random_bytes(6));

    try {
        foreach (['core', 'sdk', 'cli', 'gateway'] as $package) {
            prepareReleaseWorkflowPackage($package, '1.2.3', "{$root}/{$package}");
        }

        $core = json_decode((string) file_get_contents("{$root}/core/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $sdk = json_decode((string) file_get_contents("{$root}/sdk/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $cli = json_decode((string) file_get_contents("{$root}/cli/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $gateway = json_decode(
            (string) file_get_contents("{$root}/gateway/composer.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($core['name'])
            ->toBe('hardimpactdev/orbit-core')
            ->and($core['version'])
            ->toBe('1.2.3')
            ->and("{$root}/core/composer.lock")
            ->not->toBeFile()->and($sdk['name'])->toBe('hardimpactdev/orbit-sdk-laravel')->and($sdk['version'])->toBe(
                '1.2.3',
            )->and("{$root}/sdk/composer.lock")
            ->not->toBeFile()->and($cli['name'])->toBe('hardimpactdev/orbit-cli')->and($cli['version'])->toBe(
                '1.2.3',
            )->and($cli['require']['hardimpactdev/orbit-core'])->toBe('1.2.3')->and(
                $cli['require']['hardimpactdev/orbit-sdk-laravel'],
            )->toBe('1.2.3')->and($cli)
            ->not->toHaveKey('repositories')->and("{$root}/cli/composer.lock")
            ->not->toBeFile()->and(is_executable("{$root}/cli/orbit"))->toBeTrue()->and($gateway['name'])->toBe(
                'hardimpactdev/orbit-gateway',
            )->and($gateway['version'])->toBe('1.2.3')->and($gateway['require']['hardimpactdev/orbit-core'])->toBe(
                '1.2.3',
            )->and($gateway['require']['hardimpactdev/orbit-sdk-laravel'])->toBe('1.2.3')->and($gateway)
            ->not->toHaveKey('repositories')->and("{$root}/gateway/composer.lock")
            ->not->toBeFile()->and(is_executable("{$root}/gateway/artisan"))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
})->group('slow');
