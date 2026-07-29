<?php

declare(strict_types=1);

it('defines the fleet-scoped 9-cell matrix as an explicit workflow_dispatch release lane', function (): void {
    $workflowPath = repo_path('.github/workflows/orbit-php-cli-runtime.yml');
    expect($workflowPath)->toBeFile();

    $workflow = file_get_contents($workflowPath);
    expect($workflow)->not->toBeFalse();

    // Artifact release lane only — never ordinary PR/push feature CI.
    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('Artifact release lane')
        ->toContain('fleet-scoped')
        ->not->toContain("\n  push:")
        ->not->toContain("\n  pull_request:")
        ->not->toMatch('/^on:\s*\n(?:.*\n)*?\s+push:/m')
        ->not->toMatch('/^on:\s*\n(?:.*\n)*?\s+pull_request:/m');

    // Parse the on: block more strictly: after "on:" only workflow_dispatch until permissions/jobs.
    if (preg_match('/^on:\n((?:  .*\n)+)/m', $workflow, $onBlock) === 1) {
        expect($onBlock[1])
            ->toContain('workflow_dispatch:')
            ->not->toContain('push:')
            ->not->toContain('pull_request:');
    } else {
        expect(false)->toBeTrue('workflow on: block was not parseable');
    }

    $cells = [];
    if (preg_match_all(
        '/php_version:\s*"([^"]+)"\s*\n\s*variant:\s*(\w+)\s*\n\s*runner:\s*([^\n]+)\s*\n\s*platform:\s*([^\n]+)/',
        $workflow,
        $matches,
        PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            $cells[] = [
                'php_version' => trim($match[1]),
                'variant' => trim($match[2]),
                'runner' => trim($match[3]),
                'platform' => trim($match[4]),
            ];
        }
    }

    expect($cells)->toHaveCount(9);

    $expectedCells = [];
    foreach (['8.5.8', '8.4.21', '8.3.31'] as $patch) {
        $expectedCells[] = [
            'php_version' => $patch,
            'variant' => 'coverage',
            'runner' => 'ubuntu-24.04',
            'platform' => 'linux-x86_64',
        ];
        $expectedCells[] = [
            'php_version' => $patch,
            'variant' => 'standard',
            'runner' => 'ubuntu-24.04',
            'platform' => 'linux-x86_64',
        ];
        $expectedCells[] = [
            'php_version' => $patch,
            'variant' => 'coverage',
            'runner' => 'macos-15',
            'platform' => 'macos-aarch64',
        ];
    }

    expect($cells)->toEqualCanonicalizing($expectedCells);

    $platforms = [];
    $variants = [];
    $runners = [];

    foreach ($cells as $cell) {
        $platforms[$cell['platform']] = true;
        $variants[$cell['variant']] = true;
        $runners[$cell['runner']] = true;
        expect($cell['runner'])
            ->not->toBe('macos-13')
            ->not->toBe('macos-14')
            ->not->toBe('macos-15-intel')
            ->not->toBe('ubuntu-24.04-arm');
        expect($cell['platform'])
            ->not->toBe('linux-aarch64')
            ->not->toBe('macos-x86_64');
        // No standard macOS artifacts in the production matrix.
        if ($cell['platform'] === 'macos-aarch64') {
            expect($cell['variant'])->toBe('coverage')->and($cell['runner'])->toBe('macos-15');
        }
    }

    expect(array_keys($platforms))
        ->toEqualCanonicalizing(['linux-x86_64', 'macos-aarch64'])
        ->and(array_keys($variants))
        ->toEqualCanonicalizing(['coverage', 'standard'])
        ->and($runners)
        ->toHaveKey('ubuntu-24.04')
        ->and($runners)
        ->toHaveKey('macos-15')
        ->and($runners)
        ->not->toHaveKey('ubuntu-24.04-arm')->and($runners)
        ->not->toHaveKey('macos-15-intel');

    expect($workflow)
        ->toContain('test "$cell_count" = "9"')
        ->toContain('test "${#tarballs[@]}" -eq 9')
        // Matrix platform/runner values must not reintroduce non-fleet cells.
        ->not->toMatch('/platform:\s*linux-aarch64/')
        ->not->toMatch('/platform:\s*macos-x86_64/')
        ->not->toMatch('/runner:\s*macos-15-intel/')
        ->not->toMatch('/runner:\s*ubuntu-24.04-arm/');

    expect($workflow)
        ->toContain('publish_to_object_storage')
        ->toContain('publish_from_run_id')
        ->toContain('publish-object-storage')
        ->toContain('ORBIT_ARTIFACTS_ACCESS_KEY')
        ->toContain('ORBIT_ARTIFACTS_SECRET_KEY')
        ->toContain('ORBIT_ARTIFACTS_BUCKET')
        ->toContain('ORBIT_ARTIFACTS_ENDPOINT')
        ->toContain('ORBIT_PHP_CLI_OBJECT_PREFIX')
        ->toContain('orbit/runtimes/php-cli/sqlite-3.44.6')
        ->toContain('assemble-manifest')
        ->toContain('needs: assemble-manifest')
        ->toContain('bin/orbit-php-cli-catalog-handoff')
        ->toContain('--promote-runtime')
        // Single-object PUT for sub-5GB tarballs; never high-level multipart s3 copy uploads.
        ->toContain('s3api put-object')
        ->not->toMatch('/\bs3\s+cp\b/')
        ->not->toMatch('/\bs3\s+sync\b/')->toContain('AWS_REQUEST_CHECKSUM_CALCULATION: when_required')->toContain(
            'AWS_RESPONSE_CHECKSUM_VALIDATION: when_required',
        )->toContain('missing secret/config')
        ->not->toContain('publish_catalog_handoff')
        // Prefer runner-provided AWS CLI; never fragile PEP 668 user pip installs.
        ->toContain('aws --version')->toContain('Require AWS CLI v2')
        ->not->toContain('pip install')
        ->not->toContain('python3 -m pip')
        ->not->toContain('awscli')
        // Unauthenticated consumer URL is what install/update downloads after promotion.
        ->toContain('public_url="${artifact_base_url}/${filename}"')->toContain('curl -fsSL --retry 3')->toContain(
            'public_sha=',
        )->toContain('failed to verify public consumer URL')->toContain(
            'Upload tarballs immutably, verify public consumer URLs, then catalog handoff',
        )
        // Immutable fixed version/variant/platform keys: never overwrite differing content.
        ->toContain('s3api head-object')->toContain('head_object_metadata_sha')->toContain(
            'Definite absence only — sole path that may call put-object',
        )->toContain('skipping upload for')->toContain('IMMUTABLE OBJECT CONFLICT')->toContain(
            'IMMUTABLE HEAD AMBIGUOUS',
        )->toContain('Never overwrite a different artifact at a fixed version/variant/platform key')->toContain(
            'new versioned prefix/pin',
        )->toContain('exit 1')
        // Only definite 404/NotFound/NoSuchKey may proceed to upload; never auth/network/5xx as absence.
        ->toContain('An error occurred \\(404\\)')->toContain('An error occurred \\(NotFound\\)')->toContain(
            'An error occurred \\(NoSuchKey\\)',
        )->toContain('auth/network/5xx must not be treated as absence')->toContain('refusing put-object')
        ->not->toContain('if aws "${endpoint_args[@]}" s3api head-object')
        // Assemble must not hand off before upload; handoff lives in publish job only.
        ->toContain('Generate SHA256SUMS and collect manifests (no catalog mutation)');

    // Publication-only retry: skip rebuilds, download php-cli-matrix-handoff from a prior run.
    expect($workflow)
        ->toContain('Publication-only retry')
        ->toContain('Download assembled matrix package (prior run publication-only retry)')
        ->toContain('Download assembled matrix package (this run)')
        ->toContain('gh run download')
        ->toContain('--name php-cli-matrix-handoff')
        ->toContain('.github/workflows/orbit-php-cli-runtime.yml')
        ->toContain('publish_from_run_id must be a numeric workflow run id')
        ->toContain('has no non-expired artifact named php-cli-matrix-handoff')
        ->toContain('must contain exactly 9 tarballs and 9 manifests')
        ->toContain("inputs.publish_from_run_id == ''")
        ->toContain("inputs.publish_from_run_id != ''")
        ->toContain('actions: read')
        // Build/assemble only when not retrying publication from a prior run.
        ->toContain("if: github.event_name == 'workflow_dispatch' && inputs.publish_from_run_id == ''");

    // Host PHP for the builder (catalog JSON via php -r). macOS arm64 images may
    // ship without php; pin with the same setup-php pattern as other Orbit workflows.
    // This must not replace the static php-cli artifact build (matrix php_version).
    expect($workflow)
        ->toContain('shivammathur/setup-php@v2')
        ->toContain('php-version: "8.5"')
        ->toContain('coverage: none')
        ->toContain('Set up PHP')
        // Do not install a floating host PHP via Homebrew for the builder.
        ->not->toContain('brew install php')
        ->not->toContain('brew install php@');

    $checkoutPos = strpos($workflow, "name: Checkout\n        uses: actions/checkout@v4");
    $setupPhpPos = strpos($workflow, 'shivammathur/setup-php@v2');
    $buildStepPos = strpos($workflow, 'name: Build php-cli runtime');
    $linuxDepsPos = strpos($workflow, 'name: Install Linux build dependencies');
    $macosDepsPos = strpos($workflow, 'name: Install macOS build dependencies');

    expect($checkoutPos)
        ->not->toBeFalse()->and($setupPhpPos)
        ->not->toBeFalse()->and($buildStepPos)
        ->not->toBeFalse()->and($linuxDepsPos)
        ->not->toBeFalse()->and($macosDepsPos)
        ->not->toBeFalse()
        // Host PHP is installed for every matrix cell (Linux + both macOS runners)
        // before platform deps and before the static build.
        ->and($checkoutPos)->toBeLessThan($setupPhpPos)->and($setupPhpPos)->toBeLessThan($linuxDepsPos)->and(
            $setupPhpPos,
        )->toBeLessThan($macosDepsPos)->and($setupPhpPos)->toBeLessThan($buildStepPos);

    // Exactly one setup-php in the build job path; matrix still drives artifact versions.
    expect(substr_count($workflow, 'shivammathur/setup-php@v2'))->toBe(1);

    // SPC needs GITHUB_TOKEN for authenticated api.github.com fetches. Scope the
    // workflow-provided token to the build step only — never workflow/job-global env,
    // never a custom repository secret, and never print the token.
    $buildJobEnd = strpos($workflow, "\n  assemble-manifest:");
    expect($buildJobEnd)->not->toBeFalse();
    $buildJob = substr($workflow, 0, (int) $buildJobEnd);

    expect($buildJob)
        ->toContain("name: Build php-cli runtime\n        env:\n          GITHUB_TOKEN: \${{ github.token }}")
        ->not->toContain('echo "$GITHUB_TOKEN"')
        ->not->toContain('echo "${GITHUB_TOKEN}"')
        ->not->toContain('echo $GITHUB_TOKEN')
        ->not->toContain('printenv GITHUB_TOKEN')
        ->not->toContain('secrets.GITHUB_TOKEN')
        ->not->toContain('ORBIT_GITHUB_TOKEN')
        ->not->toContain('PERSONAL_ACCESS_TOKEN');

    // Workflow-level env must not inject GITHUB_TOKEN into every job/step.
    if (preg_match('/^env:\n((?:  .*\n)+)/m', $workflow, $workflowEnvBlock) === 1) {
        expect($workflowEnvBlock[1])->not->toContain('GITHUB_TOKEN');
    }

    // Build job-level env (if any) must not set GITHUB_TOKEN for all steps.
    if (
        preg_match(
            '/^  build:\n(?:    .*\n)*?    env:\n((?:      .*\n)+)/m',
            $workflow,
            $buildJobEnvBlock,
        ) === 1
    ) {
        expect($buildJobEnvBlock[1])->not->toContain('GITHUB_TOKEN');
    }

    // Token appears only once in the workflow (the build step), not on other jobs.
    expect(substr_count($workflow, 'GITHUB_TOKEN: ${{ github.token }}'))
        ->toBe(1)
        ->and(substr_count($workflow, 'GITHUB_TOKEN:'))
        ->toBe(1);

    // Ordering: assemble must not invoke handoff; publish job runs handoff after upload.
    $assemblePos = strpos($workflow, 'assemble-manifest:');
    $publishPos = strpos($workflow, 'publish-object-storage:');
    $assembleSection = substr($workflow, (int) $assemblePos, max(0, (int) $publishPos - (int) $assemblePos));
    $publishSection = substr($workflow, (int) $publishPos);

    // Exactly one single-object put-object in the publish job (definite-absence branch only).
    $uploadCmd = 'aws "${endpoint_args[@]}" s3api put-object';
    expect(substr_count($publishSection, $uploadCmd))
        ->toBe(1)
        ->and(substr_count($publishSection, 's3api head-object'))
        ->toBeGreaterThanOrEqual(1)
        ->and(substr_count($publishSection, 'IMMUTABLE OBJECT CONFLICT'))
        ->toBeGreaterThanOrEqual(1)
        ->and(substr_count($publishSection, 'IMMUTABLE HEAD AMBIGUOUS'))
        ->toBeGreaterThanOrEqual(1)
        ->and(substr_count($publishSection, 's3 cp'))
        ->toBe(0);

    $uploadPos = strpos($publishSection, $uploadCmd);
    $headFnPos = strpos($publishSection, 'head_object_metadata_sha');
    $conflictPos = strpos($publishSection, 'IMMUTABLE OBJECT CONFLICT');
    $ambiguousPos = strpos($publishSection, 'IMMUTABLE HEAD AMBIGUOUS');
    $handoffPos = strpos($publishSection, 'bin/orbit-php-cli-catalog-handoff');
    $skipPos = strpos($publishSection, 'skipping upload');
    $elifAbsentPos = strpos($publishSection, 'elif [ "$head_rc" -eq 1 ]');
    $priorDownloadPos = strpos($publishSection, 'Download assembled matrix package (prior run publication-only retry)');
    $thisRunDownloadPos = strpos($publishSection, 'Download assembled matrix package (this run)');
    $ghDownloadPos = strpos($publishSection, 'gh run download');

    expect($assemblePos)
        ->not->toBeFalse()->and($publishPos)
        ->not->toBeFalse()->and($assemblePos)->toBeLessThan($publishPos)->and($assembleSection)
        ->not->toContain('bin/orbit-php-cli-catalog-handoff')->and($assembleSection)->toContain(
            'no catalog mutation',
        )->and($publishSection)->toContain('bin/orbit-php-cli-catalog-handoff')->and($publishSection)->toContain(
            '--promote-runtime',
        )->and($publishSection)->toContain('aws --version')->and($publishSection)->toContain('public_url=')
        // Control flow: head gate + conflict/ambiguous exits before the single put-object upload path.
        ->and($headFnPos)
        ->not->toBeFalse()->and($uploadPos)
        ->not->toBeFalse()->and($conflictPos)
        ->not->toBeFalse()->and($ambiguousPos)
        ->not->toBeFalse()->and($handoffPos)
        ->not->toBeFalse()->and($skipPos)
        ->not->toBeFalse()->and($elifAbsentPos)
        ->not->toBeFalse()->and($headFnPos)->toBeLessThan($uploadPos)->and($conflictPos)->toBeLessThan($uploadPos)->and(
            $elifAbsentPos,
        )->toBeLessThan($uploadPos)->and($uploadPos)->toBeLessThan($handoffPos)->and($skipPos)->toBeLessThan(
            $handoffPos,
        );

    // Present / conflict / ambiguous branches exit before upload; only head_rc==1 uploads.
    expect($publishSection)
        ->toContain('Object already exists — never overwrite')
        ->toContain('elif [ "$head_rc" -eq 1 ]')
        ->toContain('Ambiguous head-object (auth/network/5xx/AccessDenied/unknown): never treat as absence')
        ->toContain('--body "$local_path"')
        ->toContain('--content-type "application/gzip"')
        // put-object flags for single-object body upload (not multipart s3 cp).
        ->toContain('--bucket "$bucket"')
        ->toContain('--key "$object_key"');

    // Publication-only path: prior-run download is gated, validates source, and never rebuilds.
    expect($priorDownloadPos)
        ->not->toBeFalse()->and($thisRunDownloadPos)
        ->not->toBeFalse()->and($ghDownloadPos)
        ->not->toBeFalse()->and($thisRunDownloadPos)->toBeLessThan($priorDownloadPos)->and(
            $priorDownloadPos,
        )->toBeLessThan($uploadPos)->and($publishSection)->toContain('publication-only retry')->and(
            $publishSection,
        )->toContain('never rebuild matrix cells')->and($publishSection)->toContain('always()')->and(
            $publishSection,
        )->toContain("needs.assemble-manifest.result == 'skipped'")->and($publishSection)->toContain(
            "needs.assemble-manifest.result == 'success'",
        )->and($publishSection)->toContain('--body "$local_path"');
});
