<?php

declare(strict_types=1);

it('defines the full 24-cell matrix as an explicit workflow_dispatch release lane', function (): void {
    $workflowPath = repo_path('.github/workflows/orbit-php-cli-runtime.yml');
    expect($workflowPath)->toBeFile();

    $workflow = file_get_contents($workflowPath);
    expect($workflow)->not->toBeFalse();

    // Artifact release lane only — never ordinary PR/push feature CI.
    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('Artifact release lane')
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

    expect($cells)->toHaveCount(24);

    $platforms = [];
    $variants = [];
    $runners = [];

    foreach ($cells as $cell) {
        $platforms[$cell['platform']] = true;
        $variants[$cell['variant']] = true;
        $runners[$cell['runner']] = true;
        expect($cell['runner'])
            ->not->toBe('macos-13')
            ->not->toBe('macos-14');
    }

    expect(array_keys($platforms))
        ->toEqualCanonicalizing([
            'linux-x86_64',
            'linux-aarch64',
            'macos-aarch64',
            'macos-x86_64',
        ])
        ->and(array_keys($variants))
        ->toEqualCanonicalizing(['coverage', 'standard'])
        ->and($runners)
        ->toHaveKey('ubuntu-24.04')
        ->and($runners)
        ->toHaveKey('ubuntu-24.04-arm')
        ->and($runners)
        ->toHaveKey('macos-15')
        ->and($runners)
        ->toHaveKey('macos-15-intel');

    // arm64 uses macos-15; intel uses macos-15-intel
    foreach ($cells as $cell) {
        if ($cell['platform'] === 'macos-aarch64') {
            expect($cell['runner'])->toBe('macos-15');
        }
        if ($cell['platform'] === 'macos-x86_64') {
            expect($cell['runner'])->toBe('macos-15-intel');
        }
    }

    expect($workflow)
        ->toContain('publish_to_object_storage')
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
        ->toContain('s3 cp')
        ->toContain('github.event_name == \'workflow_dispatch\' && inputs.publish_to_object_storage')
        ->toContain('missing secret/config')
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
            'Definite absence only — sole path that may call s3 cp',
        )->toContain('skipping upload for')->toContain('IMMUTABLE OBJECT CONFLICT')->toContain(
            'IMMUTABLE HEAD AMBIGUOUS',
        )->toContain('Never overwrite a different artifact at a fixed version/variant/platform key')->toContain(
            'new versioned prefix/pin',
        )->toContain('exit 1')
        // Only definite 404/NotFound/NoSuchKey may proceed to upload; never auth/network/5xx as absence.
        ->toContain('An error occurred \\(404\\)')->toContain('An error occurred \\(NotFound\\)')->toContain(
            'An error occurred \\(NoSuchKey\\)',
        )->toContain('auth/network/5xx must not be treated as absence')->toContain('refusing s3 cp')
        ->not->toContain('if aws "${endpoint_args[@]}" s3api head-object')
        // Assemble must not hand off before upload; handoff lives in publish job only.
        ->toContain('Generate SHA256SUMS and collect manifests (no catalog mutation)');

    // Ordering: assemble must not invoke handoff; publish job runs handoff after upload.
    $assemblePos = strpos($workflow, 'assemble-manifest:');
    $publishPos = strpos($workflow, 'publish-object-storage:');
    $assembleSection = substr($workflow, (int) $assemblePos, max(0, (int) $publishPos - (int) $assemblePos));
    $publishSection = substr($workflow, (int) $publishPos);

    // Exactly one upload command in the publish job (definite-absence branch only).
    $uploadCmd = 'aws "${endpoint_args[@]}" s3 cp';
    expect(substr_count($publishSection, $uploadCmd))
        ->toBe(1)
        ->and(substr_count($publishSection, 's3api head-object'))
        ->toBeGreaterThanOrEqual(1)
        ->and(substr_count($publishSection, 'IMMUTABLE OBJECT CONFLICT'))
        ->toBeGreaterThanOrEqual(1)
        ->and(substr_count($publishSection, 'IMMUTABLE HEAD AMBIGUOUS'))
        ->toBeGreaterThanOrEqual(1);

    $uploadPos = strpos($publishSection, $uploadCmd);
    $headFnPos = strpos($publishSection, 'head_object_metadata_sha');
    $conflictPos = strpos($publishSection, 'IMMUTABLE OBJECT CONFLICT');
    $ambiguousPos = strpos($publishSection, 'IMMUTABLE HEAD AMBIGUOUS');
    $handoffPos = strpos($publishSection, 'bin/orbit-php-cli-catalog-handoff');
    $skipPos = strpos($publishSection, 'skipping upload');
    $elifAbsentPos = strpos($publishSection, 'elif [ "$head_rc" -eq 1 ]');

    expect($assemblePos)
        ->not->toBeFalse()->and($publishPos)
        ->not->toBeFalse()->and($assemblePos)->toBeLessThan($publishPos)->and($assembleSection)
        ->not->toContain('bin/orbit-php-cli-catalog-handoff')->and($assembleSection)->toContain(
            'no catalog mutation',
        )->and($publishSection)->toContain('bin/orbit-php-cli-catalog-handoff')->and($publishSection)->toContain(
            '--promote-runtime',
        )->and($publishSection)->toContain('aws --version')->and($publishSection)->toContain('public_url=')
        // Control flow: head gate + conflict/ambiguous exits before the single s3 cp upload path.
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
        ->toContain('Ambiguous head-object (auth/network/5xx/AccessDenied/unknown): never treat as absence');
});
