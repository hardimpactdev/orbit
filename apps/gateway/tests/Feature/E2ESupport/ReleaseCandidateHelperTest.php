<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('fails build preflight when HEAD does not match origin main', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'preflight');

    try {
        $root = release_candidate_prepare_root(temp: $temp);

        $process = release_candidate_process(
            arguments: ['build'],
            env: release_candidate_process_env(root: $root, overrides: [
                'ORBIT_TEST_ORIGIN_MAIN_COMMIT' => str_repeat('b', 40),
            ]),
        );

        expect($process->getExitCode())
            ->toBe(1, $process->getOutput().$process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('Candidate artifacts must be built from the pushed origin/main commit.')
            ->and("{$root}/.orbit/release-candidates/latest")
            ->not->toBeFile();
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('fails build preflight when the tracked tree is dirty', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'dirty-tree');

    try {
        $root = release_candidate_prepare_root(temp: $temp);

        $process = release_candidate_process(
            arguments: ['build'],
            env: release_candidate_process_env(root: $root, overrides: [
                'ORBIT_TEST_GIT_STATUS' => ' M bin/orbit-release-candidate',
            ]),
        );

        expect($process->getExitCode())
            ->toBe(1, $process->getOutput().$process->getErrorOutput())
            ->and($process->getErrorOutput())
            ->toContain('clean checkout')
            ->and("{$root}/.orbit/release-candidates/latest")
            ->not->toBeFile();
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('writes durable candidate state with sha256 keys and a latest pointer during a stubbed build', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'build');

    try {
        $root = release_candidate_prepare_root(temp: $temp);

        $process = release_candidate_process(
            arguments: ['build'],
            env: release_candidate_process_env(root: $root, overrides: [
                'ORBIT_TEST_DOCKER_CONTEXT_HOST' => 'unix:///Users/nckrtl/.orbstack/run/docker.sock',
            ]),
        );

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

        $latestPointer = "{$root}/.orbit/release-candidates/latest";

        expect($latestPointer)->toBeFile();

        $buildId = trim((string) file_get_contents($latestPointer));

        expect($buildId)->toMatch('/^\d{8}T\d{6}Z-[0-9a-f]+$/');

        $stateDir = "{$root}/.orbit/release-candidates/{$buildId}";

        foreach ([
            'candidate.env',
            'gateway-image-push.log',
            'frankenphp-image-push.log',
            'orbit-linux-x64',
            'orbit-macos-arm64',
            'orbit-agent-linux-x64',
            'orbit-agent-macos-arm64',
            'orbit-release-manifest.candidate.json',
            'orbit-frankenphp-linux-amd64.tar',
            'orbit-reverb-linux-amd64.tar',
            'reverb-image-push.log',
        ] as $stateFile) {
            expect("{$stateDir}/{$stateFile}")->toBeFile();
        }

        $state = release_candidate_parse_state(path: "{$stateDir}/candidate.env");

        expect($state)->toMatchArray([
            'version' => '0.1.200',
            'build_id' => $buildId,
            'commit' => str_repeat('a', 40),
            'candidate_image' => "ghcr.io/hardimpactdev/orbit-gateway:0.1.200-candidate-{$buildId}",
            'candidate_dir' => $stateDir,
            'candidate_prefix' => "candidates/{$buildId}",
            'candidate_channel' => 'live-test',
            'candidate_asset_base_url' => "https://s3.example.test/orbit/candidates/{$buildId}",
            'gateway_digest' => 'sha256:'.str_repeat('ab', times: 32),
            'candidate_reverb_image' => "ghcr.io/hardimpactdev/orbit-reverb:0.1.200-candidate-{$buildId}",
            'reverb_digest' => 'sha256:'.str_repeat('cd', times: 32),
            'candidate_frankenphp_image' => "ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-{$buildId}",
            'stable_frankenphp_image' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
            'frankenphp_digest' => 'sha256:'.str_repeat('ef', times: 32),
            'candidate_channel_manifest_url' => 'https://s3.example.test/orbit/channels/live-test/orbit-release-manifest.json',
            'sha256_linux_amd64' => hash_file('sha256', "{$stateDir}/orbit-linux-x64"),
            'sha256_darwin_arm64' => hash_file('sha256', "{$stateDir}/orbit-macos-arm64"),
            'sha256_agent_linux_amd64' => hash_file('sha256', "{$stateDir}/orbit-agent-linux-x64"),
            'sha256_agent_darwin_arm64' => hash_file('sha256', "{$stateDir}/orbit-agent-macos-arm64"),
            'sha256_frankenphp_linux_amd64' => hash_file(
                'sha256',
                "{$stateDir}/orbit-frankenphp-linux-amd64.tar",
            ),
            'sha256_reverb_linux_amd64' => hash_file('sha256', "{$stateDir}/orbit-reverb-linux-amd64.tar"),
            'gateway_disposition' => 'built',
            'reverb_disposition' => 'built',
            'frankenphp_disposition' => 'built',
            'gateway_source_version' => '0.1.200',
            'reverb_source_version' => '0.1.200',
            'frankenphp_source_version' => '0.1.200',
            'gateway_source_build_id' => $buildId,
            'reverb_source_build_id' => $buildId,
            'frankenphp_source_build_id' => $buildId,
            'gateway_source_digest' => 'sha256:'.str_repeat('ab', times: 32),
            'reverb_source_digest' => 'sha256:'.str_repeat('cd', times: 32),
            'frankenphp_source_digest' => 'sha256:'.str_repeat('ef', times: 32),
        ]);

        expect($state['gateway_fingerprint'])
            ->toMatch('/^sha256:[0-9a-f]{64}$/')
            ->and($state['reverb_fingerprint'])
            ->toMatch('/^sha256:[0-9a-f]{64}$/')
            ->and($state['frankenphp_fingerprint'])
            ->toMatch('/^sha256:[0-9a-f]{64}$/');

        expect($process->getOutput())
            ->toContain(
                'Candidate channel manifest: https://s3.example.test/orbit/channels/live-test/orbit-release-manifest.json',
            );

        $stubLog = (string) file_get_contents("{$root}/stub.log");

        expect($stubLog)
            ->toContain(\Aws\S3\S3Client::class)
            ->toContain('putObject')
            ->toContain('fclose($stream)')
            ->toContain('orbit-release-manifest')
            ->toContain('orbit-build-cli-binary mac arm 0.1.200')
            ->toContain('orbit-build-cli-binary linux x64 0.1.200')
            ->toContain('orbit-build-agent-binary linux x64')
            ->toContain('orbit-build-agent-binary mac arm')
            ->toContain('-f docker/orbit-reverb/Dockerfile')
            ->toContain('-f docker/orbit-frankenphp/Dockerfile')
            ->toContain('--tag ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-'.$buildId)
            ->toContain(
                '--role-image=orbit-websocket=ghcr.io/hardimpactdev/orbit-reverb:0.1.200@sha256:'
                    .str_repeat('cd', times: 32),
            )
            ->toContain('--role-image-artifact=orbit-websocket=orbit-reverb-linux-amd64.tar=')
            ->toContain('--role-image-artifact=orbit-frankenphp=orbit-frankenphp-linux-amd64.tar=')
            ->toContain(
                '--role-image=orbit-frankenphp=ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-'
                    .$buildId
                    .'@sha256:'
                    .str_repeat('ef', times: 32),
            )
            ->toContain(
                'docker tag ghcr.io/hardimpactdev/orbit-reverb:0.1.200-candidate-'
                .$buildId
                .' ghcr.io/hardimpactdev/orbit-reverb:0.1.200',
            )
            ->toContain('docker save ghcr.io/hardimpactdev/orbit-reverb:0.1.200 -o ')
            ->toContain(
                'docker save ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-'.$buildId.' -o ',
            )
            ->toContain('docker context show')
            ->toContain('docker context inspect orbstack --format {{ (index .Endpoints "docker").Host }}')
            ->toContain('docker-push-host=unix:///Users/nckrtl/.orbstack/run/docker.sock')
            ->not->toContain('Storage::disk')
            ->not->toContain('release create')
            ->not->toContain('push origin')
            ->not->toContain('imagetools create');

        expect((string) file_get_contents("{$stateDir}/candidate.env"))
            ->not->toContain('stub-ghcr-token')->and((string) file_get_contents("{$stateDir}/gateway-image-push.log"))
            ->not->toContain('stub-ghcr-token')->and((string) file_get_contents("{$stateDir}/reverb-image-push.log"))
            ->not->toContain('stub-ghcr-token')->and((string) file_get_contents(
                "{$stateDir}/frankenphp-image-push.log",
            ))
            ->not->toContain('stub-ghcr-token');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('reuses unchanged Reverb and FrankenPHP digests without docker buildx build', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'reuse-unchanged');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $first = release_candidate_process(arguments: ['build'], env: $env);
        expect($first->getExitCode())->toBe(0, $first->getOutput().$first->getErrorOutput());

        $previousBuildId = release_candidate_latest_build_id(root: $root);
        $previous = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$previousBuildId}/candidate.env",
        );
        release_candidate_accept(root: $root, env: $env, buildId: $previousBuildId);

        file_put_contents("{$root}/stub.log", '');

        $second = release_candidate_process(arguments: ['build'], env: $env);
        expect($second->getExitCode())->toBe(0, $second->getOutput().$second->getErrorOutput());

        $buildId = release_candidate_latest_build_id(root: $root);
        expect($buildId)->not->toBe($previousBuildId);

        $stateDir = "{$root}/.orbit/release-candidates/{$buildId}";
        $state = release_candidate_parse_state(path: "{$stateDir}/candidate.env");
        $stubLog = (string) file_get_contents("{$root}/stub.log");

        expect($state)->toMatchArray([
            'version' => '0.1.200',
            'reverb_disposition' => 'reused',
            'frankenphp_disposition' => 'reused',
            'gateway_disposition' => 'built',
            'reverb_digest' => $previous['reverb_digest'],
            'frankenphp_digest' => $previous['frankenphp_digest'],
            'reverb_fingerprint' => $previous['reverb_fingerprint'],
            'frankenphp_fingerprint' => $previous['frankenphp_fingerprint'],
            'reverb_source_build_id' => $previousBuildId,
            'frankenphp_source_build_id' => $previousBuildId,
            'reverb_source_digest' => $previous['reverb_digest'],
            'frankenphp_source_digest' => $previous['frankenphp_digest'],
            'candidate_reverb_image' => "ghcr.io/hardimpactdev/orbit-reverb:0.1.200-candidate-{$buildId}",
            'candidate_frankenphp_image' => "ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-{$buildId}",
        ]);

        expect($stubLog)
            ->toContain('-f docker/orbit-gateway/Dockerfile')
            ->toContain(
                'docker buildx imagetools create --prefer-index=false --tag ghcr.io/hardimpactdev/orbit-reverb:0.1.200-candidate-'
                .$buildId
                .' ghcr.io/hardimpactdev/orbit-reverb@'
                .$previous['reverb_digest'],
            )
            ->toContain(
                'docker buildx imagetools inspect ghcr.io/hardimpactdev/orbit-reverb:0.1.200-candidate-'.$buildId,
            )
            ->toContain(
                'docker buildx imagetools create --prefer-index=false --tag ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-'
                .$buildId
                .' ghcr.io/hardimpactdev/orbit-frankenphp@'
                .$previous['frankenphp_digest'],
            )
            ->toContain(
                'docker buildx imagetools inspect ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-'
                .$buildId,
            )
            ->toContain(
                'docker tag ghcr.io/hardimpactdev/orbit-reverb:0.1.200-candidate-'
                .$buildId
                .' ghcr.io/hardimpactdev/orbit-reverb:0.1.200',
            )
            ->toContain('docker save ghcr.io/hardimpactdev/orbit-reverb:0.1.200 -o ')
            ->toContain(
                'docker save ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-'.$buildId.' -o ',
            )
            ->not->toContain('-f docker/orbit-reverb/Dockerfile')
            ->not->toContain('-f docker/orbit-frankenphp/Dockerfile');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('rebuilds Reverb when owned-input fingerprints change', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'reuse-changed');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $first = release_candidate_process(arguments: ['build'], env: $env);
        expect($first->getExitCode())->toBe(0, $first->getOutput().$first->getErrorOutput());

        $previousBuildId = release_candidate_latest_build_id(root: $root);
        release_candidate_accept(root: $root, env: $env, buildId: $previousBuildId);
        $previousPath = "{$root}/.orbit/release-candidates/{$previousBuildId}/candidate.env";
        $previous = (string) file_get_contents($previousPath);
        file_put_contents(
            $previousPath,
            (string) preg_replace(
                '/^reverb_fingerprint=.*$/m',
                'reverb_fingerprint=sha256:'.str_repeat('11', times: 32),
                $previous,
            ),
        );

        file_put_contents("{$root}/stub.log", '');

        $second = release_candidate_process(arguments: ['build'], env: $env);
        expect($second->getExitCode())->toBe(0, $second->getOutput().$second->getErrorOutput());

        $buildId = release_candidate_latest_build_id(root: $root);
        $state = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$buildId}/candidate.env",
        );
        $stubLog = (string) file_get_contents("{$root}/stub.log");

        expect($state)
            ->toMatchArray([
                'reverb_disposition' => 'built',
                'frankenphp_disposition' => 'reused',
                'reverb_source_build_id' => $buildId,
            ])
            ->and($stubLog)
            ->toContain('-f docker/orbit-reverb/Dockerfile')
            ->not->toContain('-f docker/orbit-frankenphp/Dockerfile');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('rebuilds reusable images when previous metadata is missing malformed or force-rebuild is set', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'reuse-fallback');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $missing = release_candidate_process(arguments: ['build'], env: $env);
        expect($missing->getExitCode())->toBe(0, $missing->getOutput().$missing->getErrorOutput());
        $missingState = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/".release_candidate_latest_build_id(root: $root).'/candidate.env',
        );
        expect($missingState)->toMatchArray([
            'reverb_disposition' => 'built',
            'frankenphp_disposition' => 'built',
            'gateway_disposition' => 'built',
        ]);

        $malformedId = release_candidate_latest_build_id(root: $root);
        release_candidate_accept(root: $root, env: $env, buildId: $malformedId);
        $malformedPath = "{$root}/.orbit/release-candidates/{$malformedId}/candidate.env";
        $malformed = (string) file_get_contents($malformedPath);
        file_put_contents(
            $malformedPath,
            (string) preg_replace('/^reverb_digest=.*$/m', 'reverb_digest=not-a-digest', $malformed),
        );

        file_put_contents("{$root}/stub.log", '');

        $malformedBuild = release_candidate_process(arguments: ['build'], env: $env);
        expect($malformedBuild->getExitCode())
            ->toBe(
                0,
                $malformedBuild->getOutput().$malformedBuild->getErrorOutput(),
            );
        expect((string) file_get_contents("{$root}/stub.log"))->toContain('-f docker/orbit-reverb/Dockerfile');

        $forcePrevious = release_candidate_latest_build_id(root: $root);
        file_put_contents("{$root}/stub.log", '');

        $forced = release_candidate_process(arguments: ['build', '--force-rebuild=reverb'], env: $env);
        expect($forced->getExitCode())->toBe(0, $forced->getOutput().$forced->getErrorOutput());

        $forceBuildId = release_candidate_latest_build_id(root: $root);
        $forceState = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$forceBuildId}/candidate.env",
        );
        $forceLog = (string) file_get_contents("{$root}/stub.log");

        expect($forceBuildId)
            ->not->toBe($forcePrevious)->and($forceState)->toMatchArray([
                'reverb_disposition' => 'built',
                'frankenphp_disposition' => 'reused',
                'reverb_source_build_id' => $forceBuildId,
            ])->and($forceLog)->toContain('-f docker/orbit-reverb/Dockerfile')
            ->not->toContain('-f docker/orbit-frankenphp/Dockerfile');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('still builds the gateway image when a previous gateway fingerprint matches', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'gateway-always-build');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $first = release_candidate_process(arguments: ['build'], env: $env);
        expect($first->getExitCode())->toBe(0, $first->getOutput().$first->getErrorOutput());
        release_candidate_accept(
            root: $root,
            env: $env,
            buildId: release_candidate_latest_build_id(root: $root),
        );

        file_put_contents("{$root}/stub.log", '');

        $second = release_candidate_process(arguments: ['build'], env: $env);
        expect($second->getExitCode())->toBe(0, $second->getOutput().$second->getErrorOutput());

        $buildId = release_candidate_latest_build_id(root: $root);
        $state = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$buildId}/candidate.env",
        );

        expect($state['gateway_disposition'])
            ->toBe('built')
            ->and($state['gateway_source_build_id'])
            ->toBe($buildId)
            ->and((string) file_get_contents("{$root}/stub.log"))
            ->toContain('-f docker/orbit-gateway/Dockerfile');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('fails the candidate when a reused destination digest does not match', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'reuse-digest-mismatch');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $first = release_candidate_process(arguments: ['build'], env: $env);
        expect($first->getExitCode())->toBe(0, $first->getOutput().$first->getErrorOutput());
        release_candidate_accept(
            root: $root,
            env: $env,
            buildId: release_candidate_latest_build_id(root: $root),
        );

        $second = release_candidate_process(
            arguments: ['build'],
            env: release_candidate_process_env(root: $root, overrides: [
                'ORBIT_TEST_REVERB_DIGEST' => 'sha256:'.str_repeat('99', times: 32),
            ]),
        );

        expect($second->getExitCode())
            ->toBe(1, $second->getOutput().$second->getErrorOutput())
            ->and($second->getErrorOutput())
            ->toContain('digest mismatch')
            ->toContain('--force-rebuild=');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('does not reuse an unaccepted newer latest candidate when an older accepted candidate exists', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'reuse-accepted-not-latest');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $first = release_candidate_process(arguments: ['build'], env: $env);
        expect($first->getExitCode())->toBe(0, $first->getOutput().$first->getErrorOutput());
        $acceptedId = release_candidate_latest_build_id(root: $root);
        $accepted = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$acceptedId}/candidate.env",
        );
        release_candidate_accept(root: $root, env: $env, buildId: $acceptedId);

        $second = release_candidate_process(arguments: ['build'], env: $env);
        expect($second->getExitCode())->toBe(0, $second->getOutput().$second->getErrorOutput());
        $unacceptedId = release_candidate_latest_build_id(root: $root);
        expect($unacceptedId)->not->toBe($acceptedId);

        $unacceptedPath = "{$root}/.orbit/release-candidates/{$unacceptedId}/candidate.env";
        $unaccepted = (string) file_get_contents($unacceptedPath);
        file_put_contents(
            $unacceptedPath,
            (string) preg_replace(
                '/^reverb_digest=.*$/m',
                'reverb_digest=sha256:'.str_repeat('22', times: 32),
                $unaccepted,
            ),
        );

        $third = release_candidate_process(arguments: ['build'], env: $env);
        expect($third->getExitCode())->toBe(0, $third->getOutput().$third->getErrorOutput());

        $thirdId = release_candidate_latest_build_id(root: $root);
        $thirdState = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$thirdId}/candidate.env",
        );

        expect($thirdState)
            ->toMatchArray([
                'reverb_disposition' => 'reused',
                'reverb_digest' => $accepted['reverb_digest'],
                'reverb_source_build_id' => $acceptedId,
            ])
            ->and($thirdState['reverb_source_build_id'])
            ->not->toBe($unacceptedId);
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('exports a websocket archive whose RepoTags match the versioned manifest image', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'archive-repotags');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $process = release_candidate_process(
            arguments: ['build'],
            env: release_candidate_process_env(root: $root),
        );
        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

        $buildId = release_candidate_latest_build_id(root: $root);
        $stateDir = "{$root}/.orbit/release-candidates/{$buildId}";
        $archive = "{$stateDir}/orbit-reverb-linux-amd64.tar";
        $expectedTag = 'ghcr.io/hardimpactdev/orbit-reverb:0.1.200';
        $stubLog = (string) file_get_contents("{$root}/stub.log");

        expect($stubLog)
            ->toContain("docker tag ghcr.io/hardimpactdev/orbit-reverb:0.1.200-candidate-{$buildId} {$expectedTag}")
            ->toContain("docker save {$expectedTag} -o ")
            ->toContain('--role-image=orbit-websocket='.$expectedTag.'@sha256:'.str_repeat('cd', times: 32))
            ->not->toMatch('/docker push ghcr\.io\/hardimpactdev\/orbit-reverb:0\.1\.200(?:\s|$)/');

        expect(release_candidate_archive_repo_tags(path: $archive))->toBe([$expectedTag]);
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('rebuilds Reverb when a fixture owned input changes and reuses when an unrelated file changes', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'fingerprint-fixture');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $first = release_candidate_process(arguments: ['build'], env: $env);
        expect($first->getExitCode())->toBe(0, $first->getOutput().$first->getErrorOutput());
        $firstId = release_candidate_latest_build_id(root: $root);
        $firstState = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$firstId}/candidate.env",
        );
        release_candidate_accept(root: $root, env: $env, buildId: $firstId);

        file_put_contents("{$root}/README.md", "unrelated\n");
        $unrelated = release_candidate_process(arguments: ['build'], env: $env);
        expect($unrelated->getExitCode())->toBe(0, $unrelated->getOutput().$unrelated->getErrorOutput());
        $unrelatedId = release_candidate_latest_build_id(root: $root);
        $unrelatedState = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$unrelatedId}/candidate.env",
        );

        expect($unrelatedState)->toMatchArray([
            'reverb_disposition' => 'reused',
            'frankenphp_disposition' => 'reused',
            'reverb_fingerprint' => $firstState['reverb_fingerprint'],
            'frankenphp_fingerprint' => $firstState['frankenphp_fingerprint'],
        ]);

        release_candidate_accept(root: $root, env: $env, buildId: $unrelatedId);
        file_put_contents("{$root}/apps/reverb/composer.lock", "{\"changed\":true}\n");

        $changed = release_candidate_process(arguments: ['build'], env: $env);
        expect($changed->getExitCode())->toBe(0, $changed->getOutput().$changed->getErrorOutput());
        $changedId = release_candidate_latest_build_id(root: $root);
        $changedState = release_candidate_parse_state(
            path: "{$root}/.orbit/release-candidates/{$changedId}/candidate.env",
        );
        $changedLog = (string) file_get_contents("{$root}/stub.log");

        expect($changedState['reverb_disposition'])
            ->toBe('built')
            ->and($changedState['frankenphp_disposition'])
            ->toBe('reused')
            ->and($changedState['reverb_fingerprint'])
            ->not
            ->toBe($firstState['reverb_fingerprint'])
            ->and($changedState['frankenphp_fingerprint'])
            ->toBe($firstState['frankenphp_fingerprint'])
            ->and($changedLog)
            ->toContain('-f docker/orbit-reverb/Dockerfile');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('imports a verified Darwin Agent bundle instead of building it locally', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'native-import');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $nativeDir = release_candidate_write_native_bundle(
            root: $root,
            commit: str_repeat('a', 40),
            version: '0.1.200',
        );

        $process = release_candidate_process(
            arguments: ['build', "--native-assets={$nativeDir}"],
            env: release_candidate_process_env(root: $root),
        );

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

        $stubLog = (string) file_get_contents("{$root}/stub.log");
        expect($stubLog)
            ->toContain('orbit-build-agent-binary linux x64')
            ->not->toContain('orbit-build-agent-binary mac arm');

        $latestPointer = "{$root}/.orbit/release-candidates/latest";
        expect($latestPointer)->toBeFile();
        $buildId = trim((string) file_get_contents($latestPointer));
        $stateDir = "{$root}/.orbit/release-candidates/{$buildId}";
        $state = release_candidate_parse_state(path: "{$stateDir}/candidate.env");

        expect($state)
            ->toMatchArray([
                'sha256_agent_darwin_arm64' => hash_file('sha256', "{$nativeDir}/orbit-agent-macos-arm64"),
                'native_builder_os' => 'Darwin',
                'native_builder_arch' => 'arm64',
                'native_source_commit' => str_repeat('a', 40),
            ])
            ->and((string) file_get_contents("{$stateDir}/orbit-agent-macos-arm64"))
            ->toBe((string) file_get_contents("{$nativeDir}/orbit-agent-macos-arm64"));
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('rejects an imported native bundle that fails verification', function (
    string $key,
    string $value,
    string $needle,
): void {
    $temp = release_candidate_make_temp_dir(suffix: 'native-fail-'.$key);

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $nativeDir = release_candidate_write_native_bundle(
            root: $root,
            commit: str_repeat('a', 40),
            version: '0.1.200',
            manifestOverrides: [$key => $value],
        );

        $process = release_candidate_process(
            arguments: ['build', "--native-assets={$nativeDir}"],
            env: release_candidate_process_env(root: $root),
        );

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain($needle);

        $stubLog = (string) file_get_contents("{$root}/stub.log");
        expect($stubLog)
            ->not->toContain('docker buildx build')
            ->not->toContain('docker push')
            ->not->toContain('putObject');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
})->with([
    'wrong commit' => ['commit', str_repeat('b', 40), 'native source commit'],
    'wrong version' => ['version', '9.9.9', 'native source version'],
    'wrong builder os' => ['builder_os', 'Linux', 'builder_os'],
    'wrong builder arch' => ['builder_arch', 'x86_64', 'builder_arch'],
    'wrong hash' => ['sha256_agent_darwin_arm64', str_repeat('0', 64), 'sha256_agent_darwin_arm64'],
]);

it('rejects imported native bundles with inventory or architecture problems', function (
    string $case,
    string $needle,
): void {
    $temp = release_candidate_make_temp_dir(suffix: 'native-inventory-'.preg_replace('/[^a-z]+/', '-', $case));

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $nativeDir = release_candidate_write_native_bundle(
            root: $root,
            commit: str_repeat('a', 40),
            version: '0.1.200',
        );
        $env = release_candidate_process_env(root: $root);

        if ($case === 'missing artifact') {
            unlink("{$nativeDir}/orbit-agent-macos-arm64");
        } elseif ($case === 'extra file') {
            file_put_contents("{$nativeDir}/extra.bin", "undeclared\n");
        } elseif ($case === 'symlink') {
            $realDir = "{$nativeDir}.real";
            rename($nativeDir, $realDir);
            symlink($realDir, $nativeDir);
        } elseif ($case === 'blank line') {
            file_put_contents("{$nativeDir}/native-assets.env", "\n", FILE_APPEND);
        } elseif ($case === 'malformed line') {
            file_put_contents("{$nativeDir}/native-assets.env", "not-a-key-value\n", FILE_APPEND);
        } else {
            $env['ORBIT_TEST_FILE_RESULT'] = 'ELF 64-bit LSB executable, x86-64';
        }

        $process = release_candidate_process(
            arguments: ['build', "--native-assets={$nativeDir}"],
            env: $env,
        );

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain($needle);

        $stubLog = (string) file_get_contents("{$root}/stub.log");
        expect($stubLog)
            ->not->toContain('docker buildx build')
            ->not->toContain('docker push')
            ->not->toContain('putObject');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
})->with([
    'missing artifact' => ['missing artifact', 'orbit-agent-macos-arm64 is missing or unsafe'],
    'extra file' => ['extra file', 'native assets directory contains missing or undeclared files'],
    'symlink' => ['symlink', 'native assets path must be a directory, not a symlink'],
    'wrong file type' => ['wrong file type', 'native Agent artifact is not a Mach-O arm64 executable'],
    'blank line' => ['blank line', 'native manifest contains blank, malformed, or extra lines'],
    'malformed line' => ['malformed line', 'native manifest contains blank, malformed, or extra lines'],
]);

it('rejects verify-native when the expected commit or version is not a valid identity', function (
    string $commit,
    string $version,
    array $arguments,
    array $env,
    string $needle,
): void {
    $temp = release_candidate_make_temp_dir(suffix: 'verify-native-identity-'.preg_replace('/[^a-z]+/', '-', $needle));

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $nativeDir = release_candidate_write_native_bundle(
            root: $root,
            commit: $commit,
            version: $version,
        );

        $process = release_candidate_process(
            arguments: ['verify-native', "--native-assets={$nativeDir}", ...$arguments],
            env: release_candidate_process_env(root: $root, overrides: $env),
        );

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain($needle);

        expect((string) file_get_contents("{$root}/stub.log"))
            ->not->toContain('docker buildx build')
            ->not->toContain('docker push')
            ->not->toContain('putObject');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
})->with([
    'supplied commit' => [
        'not-a-sha',
        '0.1.200',
        ['--commit=not-a-sha'],
        [],
        'native expected commit is invalid',
    ],
    'supplied version' => [
        str_repeat('a', 40),
        'latest',
        ['--version=latest'],
        [],
        'native expected version is invalid',
    ],
    'derived commit' => [
        'not-a-derived-sha',
        '0.1.200',
        [],
        ['ORBIT_TEST_HEAD_COMMIT' => 'not-a-derived-sha'],
        'native expected commit is invalid',
    ],
]);

it('fails the candidate build when the imported Agent bytes change after copy', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'native-copy-tamper');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $nativeDir = release_candidate_write_native_bundle(
            root: $root,
            commit: str_repeat('a', 40),
            version: '0.1.200',
        );

        release_candidate_write_stub(binDir: "{$root}/bin", name: 'cp', body: <<<'BASH'
            printf 'cp %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
            src="$1"
            dest="$2"
            if [ "${src##*/}" = 'orbit-agent-macos-arm64' ] && [ "${dest##*/}" = 'orbit-agent-macos-arm64' ]; then
                printf 'tampered-imported-agent\n' > "$dest"
                chmod 0755 "$dest"
                exit 0
            fi
            /bin/cp "$src" "$dest"
            BASH);

        $process = release_candidate_process(
            arguments: ['build', "--native-assets={$nativeDir}"],
            env: release_candidate_process_env(root: $root),
        );

        expect($process->getExitCode())
            ->toBe(1)
            ->and($process->getErrorOutput())
            ->toContain('imported Agent artifact changed after copy');

        $stubLog = (string) file_get_contents("{$root}/stub.log");
        expect($stubLog)
            ->not->toContain('docker buildx build')
            ->not->toContain('docker push')
            ->not->toContain('putObject');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('accepts GNU libmagic Mach-O arm64 file output when verifying native assets', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'verify-native-gnu-file');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $nativeDir = release_candidate_write_native_bundle(
            root: $root,
            commit: str_repeat('a', 40),
            version: '0.1.200',
        );

        $process = release_candidate_process(
            arguments: ['verify-native', "--native-assets={$nativeDir}"],
            env: release_candidate_process_env(root: $root, overrides: [
                'ORBIT_TEST_FILE_RESULT' => 'Mach-O 64-bit arm64 executable, flags:<NOUNDEFS|DYLDLINK|TWOLEVEL|PIE>',
            ]),
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PASS sha256_agent_darwin_arm64');

        expect((string) file_get_contents("{$root}/stub.log"))
            ->not->toContain('docker buildx build')
            ->not->toContain('docker push')
            ->not->toContain('putObject');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('verifies a native bundle without building or publishing a candidate', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'verify-native');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $nativeDir = release_candidate_write_native_bundle(
            root: $root,
            commit: str_repeat('a', 40),
            version: '0.1.200',
        );

        $process = release_candidate_process(
            arguments: ['verify-native', "--native-assets={$nativeDir}"],
            env: release_candidate_process_env(root: $root),
        );

        expect($process->getExitCode())
            ->toBe(0)
            ->and($process->getOutput())
            ->toContain('PASS sha256_agent_darwin_arm64');

        expect((string) file_get_contents("{$root}/stub.log"))
            ->not->toContain('docker buildx build')
            ->not->toContain('docker push')
            ->not->toContain('putObject');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('promotes the accepted FrankenPHP candidate digest without creating a GitHub release', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'promote-runtime');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);
        $buildId = '20260701T000000Z-abcdef12';
        $stateDir = release_candidate_write_state(root: $root, buildId: $buildId, pointLatest: true);
        $latestBuildId = '20260702T000000Z-bbbbbbbb';
        release_candidate_write_state(root: $root, buildId: $latestBuildId, pointLatest: true);

        $withoutAcceptance = release_candidate_process(
            arguments: ['promote-runtime', "--build-id={$buildId}"],
            env: $env,
        );
        $withoutIdentity = release_candidate_process(arguments: ['promote-runtime', '--accepted'], env: $env);

        expect($withoutAcceptance->getExitCode())
            ->toBe(1)
            ->and($withoutAcceptance->getErrorOutput())
            ->toContain('--accepted')
            ->and($withoutIdentity->getExitCode())
            ->toBe(1)
            ->and($withoutIdentity->getErrorOutput())
            ->toContain('--build-id=<accepted-id>');

        $process = release_candidate_process(
            arguments: ['promote-runtime', "--build-id={$buildId}", '--accepted'],
            env: [
                ...$env,
                'DOCKER_CONFIG' => 'home/.docker',
                'ORBIT_TEST_REQUIRE_BUILDX_PLUGIN' => '1',
            ],
            cwd: $root,
        );

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PASS frankenphp_digest sha256:'.str_repeat('ef', times: 32))
            ->and("{$stateDir}/frankenphp-promotion.log")
            ->toBeFile();

        $stubLog = (string) file_get_contents("{$root}/stub.log");

        expect($stubLog)
            ->toContain(
                'docker buildx imagetools create --prefer-index=false --tag ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm '
                    ."ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-{$buildId}@sha256:"
                    .str_repeat('ef', times: 32),
            )
            ->toContain(
                'docker buildx imagetools inspect ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
            )
            ->not->toContain("orbit-frankenphp:2-php8.5-bookworm-candidate-{$latestBuildId}@sha256:")
            ->not->toContain('release create')
            ->not->toContain('push origin');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('prints eval-able exports for candidate state and fails cleanly with no state', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'env');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        $missing = release_candidate_process(arguments: ['env'], env: $env);

        expect($missing->getExitCode())
            ->not
            ->toBe(0)
            ->and($missing->getErrorOutput())
            ->toContain('No release candidate state');

        $buildId = '20260701T000000Z-abcdef12';
        $stateDir = release_candidate_write_state(root: $root, buildId: $buildId, pointLatest: true);

        $process = release_candidate_process(arguments: ['env'], env: $env);

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('export version=9.9.9')
            ->toContain("export build_id={$buildId}")
            ->toContain("export candidate_dir={$stateDir}")
            ->toContain('export sha256_linux_amd64=')
            ->toContain('export sha256_darwin_arm64=')
            ->toContain('export sha256_agent_linux_amd64=')
            ->toContain('export sha256_agent_darwin_arm64=');

        $eval = new Process(
            [
                'bash',
                '-c',
                'eval "$("$0" env)" && printf %s "$candidate_image"',
                repo_path('bin/orbit-release-candidate'),
            ],
            repo_path(),
            $env,
        );
        $eval->run();

        expect($eval->getExitCode())
            ->toBe(0, $eval->getOutput().$eval->getErrorOutput())
            ->and($eval->getOutput())
            ->toBe("ghcr.io/hardimpactdev/orbit-gateway:9.9.9-candidate-{$buildId}");
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('verifies intact artifacts and fails naming the tampered sha256 key', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'verify');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);
        $buildId = '20260701T000000Z-abcdef12';
        $stateDir = release_candidate_write_state(root: $root, buildId: $buildId, pointLatest: true);

        $pass = release_candidate_process(
            arguments: ['verify', '--release-image=ghcr.io/hardimpactdev/orbit-gateway:9.9.9'],
            env: $env,
        );

        expect($pass->getExitCode())
            ->toBe(0, $pass->getOutput().$pass->getErrorOutput())
            ->and($pass->getOutput())
            ->toContain('PASS sha256_linux_amd64')
            ->toContain('PASS sha256_darwin_arm64')
            ->toContain('PASS sha256_agent_linux_amd64')
            ->toContain('PASS sha256_agent_darwin_arm64')
            ->toContain('PASS sha256_frankenphp_linux_amd64')
            ->toContain('PASS sha256_reverb_linux_amd64')
            ->toContain('PASS gateway_digest');

        file_put_contents("{$stateDir}/orbit-linux-x64", "tampered\n", FILE_APPEND);

        $fail = release_candidate_process(arguments: ['verify'], env: $env);

        expect($fail->getExitCode())
            ->not
            ->toBe(0)
            ->and($fail->getOutput())
            ->toContain('FAIL sha256_linux_amd64')
            ->toContain('PASS sha256_darwin_arm64')
            ->toContain('PASS sha256_agent_linux_amd64')
            ->toContain('PASS sha256_agent_darwin_arm64')
            ->toContain('PASS sha256_frankenphp_linux_amd64')
            ->toContain('PASS sha256_reverb_linux_amd64');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('reports an imagetools inspect failure as a gateway_digest mismatch without aborting the verify report', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'verify-inspect');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root, overrides: [
            'ORBIT_TEST_IMAGETOOLS_FAIL' => '1',
        ]);
        release_candidate_write_state(root: $root, buildId: '20260701T000000Z-abcdef12', pointLatest: true);

        $process = release_candidate_process(
            arguments: ['verify', '--release-image=ghcr.io/hardimpactdev/orbit-gateway:9.9.9'],
            env: $env,
        );

        expect($process->getExitCode())
            ->toBe(1, $process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('PASS sha256_linux_amd64')
            ->toContain('PASS sha256_darwin_arm64')
            ->toContain('PASS sha256_agent_linux_amd64')
            ->toContain('PASS sha256_agent_darwin_arm64')
            ->toContain('PASS sha256_frankenphp_linux_amd64')
            ->toContain('PASS sha256_reverb_linux_amd64')
            ->toContain('FAIL gateway_digest: imagetools inspect failed for ghcr.io/hardimpactdev/orbit-gateway:9.9.9')
            ->and($process->getErrorOutput())
            ->toContain('verify failed with 1 mismatch(es)');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('rejects --build-id values that do not match the build id pattern', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'build-id-pattern');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        mkdir("{$root}/.orbit/release-candidates", recursive: true);
        mkdir("{$root}/evil", recursive: true);
        file_put_contents("{$root}/evil/candidate.env", "version=6.6.6\n");

        $traversalEnv = release_candidate_process(arguments: ['env', '--build-id=../../evil'], env: $env);
        $traversalVerify = release_candidate_process(arguments: ['verify', '--build-id=../../evil'], env: $env);

        expect($traversalEnv->getExitCode())
            ->toBe(1, $traversalEnv->getOutput().$traversalEnv->getErrorOutput())
            ->and($traversalEnv->getOutput())
            ->not
            ->toContain('6.6.6')
            ->and($traversalEnv->getErrorOutput())
            ->toContain('../../evil')
            ->toContain('[0-9]{8}T[0-9]{6}Z-[0-9a-f]+')
            ->and($traversalVerify->getExitCode())
            ->toBe(1, $traversalVerify->getOutput().$traversalVerify->getErrorOutput())
            ->and($traversalVerify->getErrorOutput())
            ->toContain('[0-9]{8}T[0-9]{6}Z-[0-9a-f]+');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

it('resolves --build-id ahead of the latest pointer', function (): void {
    $temp = release_candidate_make_temp_dir(suffix: 'build-id');

    try {
        $root = release_candidate_prepare_root(temp: $temp);
        $env = release_candidate_process_env(root: $root);

        release_candidate_write_state(
            root: $root,
            buildId: '20260701T000000Z-aaaaaaaa',
            overrides: ['version' => '1.1.1'],
            pointLatest: true,
        );
        release_candidate_write_state(root: $root, buildId: '20260702T000000Z-bbbbbbbb', overrides: [
            'version' => '2.2.2',
        ]);

        $latest = release_candidate_process(arguments: ['env'], env: $env);
        $named = release_candidate_process(arguments: ['env', '--build-id=20260702T000000Z-bbbbbbbb'], env: $env);
        $unknown = release_candidate_process(arguments: ['env', '--build-id=20990101T000000Z-ffffffff'], env: $env);

        expect($latest->getExitCode())
            ->toBe(0, $latest->getOutput().$latest->getErrorOutput())
            ->and($latest->getOutput())
            ->toContain('export version=1.1.1')
            ->and($named->getExitCode())
            ->toBe(0, $named->getOutput().$named->getErrorOutput())
            ->and($named->getOutput())
            ->toContain('export version=2.2.2')
            ->toContain('export build_id=20260702T000000Z-bbbbbbbb')
            ->and($unknown->getExitCode())
            ->not
            ->toBe(0)
            ->and($unknown->getErrorOutput())
            ->toContain('20990101T000000Z-ffffffff');
    } finally {
        release_candidate_remove_temp_dir(path: $temp);
    }
});

function release_candidate_make_temp_dir(string $suffix): string
{
    $temp = sys_get_temp_dir().'/orbit-release-candidate-'.$suffix.'-'.bin2hex(random_bytes(6));

    mkdir($temp, recursive: true);

    return $temp;
}

function release_candidate_remove_temp_dir(string $path): void
{
    if ($path === '' || ! str_contains($path, '/orbit-release-candidate-')) {
        return;
    }

    new Process(['rm', '-rf', $path])->run();
}

/**
 * Builds a fake repository root whose bin/ directory doubles as the stub PATH
 * for docker, gh, git, and the bin helper launchers the release-candidate
 * helper shells out to.
 */
function release_candidate_prepare_root(string $temp): string
{
    $root = "{$temp}/root";

    mkdir("{$root}/bin", recursive: true);
    mkdir("{$root}/home", recursive: true);
    mkdir("{$root}/home/.docker/cli-plugins", recursive: true);
    file_put_contents("{$root}/release.env", "ORBIT_TEST_RELEASE_ENV=sourced\n");
    file_put_contents("{$root}/stub.log", '');
    file_put_contents(
        filename: "{$root}/home/.docker/cli-plugins/docker-buildx",
        data: "#!/usr/bin/env bash\nexit 0\n",
    );
    chmod(filename: "{$root}/home/.docker/cli-plugins/docker-buildx", permissions: 0o755);

    release_candidate_write_owned_inputs(root: $root);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'git', body: <<<'BASH'
        printf 'git %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        git_dir="${ORBIT_RELEASE_CANDIDATE_ROOT:-.}"
        while [ "${1:-}" = '-C' ]; do
            git_dir="$2"
            shift 2
        done
        if [ "${1:-}" = 'ls-tree' ]; then
            path=''
            while [ "$#" -gt 0 ]; do
                if [ "$1" = '--' ]; then
                    shift
                    path="${1:-}"
                    break
                fi
                shift
            done
            [ -n "$path" ] || exit 1
            target="${git_dir}/${path}"
            emit_tree() {
                local file="$1"
                local rel="$2"
                local hash
                if command -v sha256sum >/dev/null 2>&1; then
                    hash="$(sha256sum "$file" | awk '{ print $1 }')"
                else
                    hash="$(shasum -a 256 "$file" | awk '{ print $1 }')"
                fi
                printf '100644 blob %s\t%s\n' "$hash" "$rel"
            }
            if [ -f "$target" ]; then
                emit_tree "$target" "$path"
                exit 0
            fi
            if [ -d "$target" ]; then
                found=0
                while IFS= read -r file; do
                    rel="${file#"${git_dir}"/}"
                    emit_tree "$file" "$rel"
                    found=1
                done < <(find "$target" -type f | LC_ALL=C sort)
                [ "$found" -eq 1 ] || exit 1
                exit 0
            fi
            exit 1
        fi
        case "$*" in
            'rev-parse HEAD') printf '%s\n' "${ORBIT_TEST_HEAD_COMMIT}" ;;
            'rev-parse --short HEAD') printf '%s\n' "${ORBIT_TEST_HEAD_COMMIT:0:8}" ;;
            'ls-remote origin refs/heads/main') printf '%s\trefs/heads/main\n' "${ORBIT_TEST_ORIGIN_MAIN_COMMIT}" ;;
            'status --porcelain') [ -z "${ORBIT_TEST_GIT_STATUS:-}" ] || printf '%s\n' "${ORBIT_TEST_GIT_STATUS}" ;;
            *) exit 1 ;;
        esac
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'file', body: <<<'BASH'
        printf 'file %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        printf '%s: %s\n' "$1" "${ORBIT_TEST_FILE_RESULT:-Mach-O 64-bit executable arm64}"
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'docker', body: <<<'BASH'
        printf 'docker %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        if [ "$1" = 'buildx' ] && [ -n "${ORBIT_TEST_REQUIRE_BUILDX_PLUGIN:-}" ] \
            && [ ! -x "${DOCKER_CONFIG:-}/cli-plugins/docker-buildx" ]; then
            printf 'docker: unknown command: docker buildx\n' >&2
            exit 1
        fi
        if [ "$1" = 'context' ] && [ "$2" = 'show' ]; then
            printf '%s\n' "${ORBIT_TEST_DOCKER_CONTEXT_NAME:-orbstack}"
            exit 0
        fi
        if [ "$1" = 'context' ] && [ "$2" = 'inspect' ]; then
            printf '%s\n' "${ORBIT_TEST_DOCKER_CONTEXT_HOST:-}"
            exit 0
        fi
        if [ "$1" = 'buildx' ] && [ "$2" = 'imagetools' ] && [ "$3" = 'inspect' ]; then
            if [ -n "${ORBIT_TEST_IMAGETOOLS_FAIL:-}" ]; then
                printf 'ERROR: %s: not found\n' "$4" >&2
                exit 1
            fi
            printf 'Name:      %s\n' "$4"
            printf 'MediaType: application/vnd.oci.image.index.v1+json\n'
            case "$4" in
                *orbit-reverb*) digest="${ORBIT_TEST_REVERB_DIGEST}" ;;
                *orbit-frankenphp*) digest="${ORBIT_TEST_FRANKENPHP_DIGEST}" ;;
                *) digest="${ORBIT_TEST_GATEWAY_DIGEST}" ;;
            esac
            printf 'Digest:    %s\n' "$digest"
            exit 0
        fi
        if [ "$1" = 'buildx' ] && [ "$2" = 'imagetools' ] && [ "$3" = 'create' ]; then
            exit 0
        fi
        if [ "$1" = 'buildx' ] && [ "$2" = 'build' ]; then
            exit 0
        fi
        if [ "$1" = 'pull' ]; then
            exit 0
        fi
        if [ "$1" = 'tag' ]; then
            exit 0
        fi
        if [ "$1" = 'push' ]; then
            printf 'docker-push-host=%s\n' "${DOCKER_HOST:-}" >> "${STUB_LOG:-/dev/null}"
            case "$2" in
                *orbit-reverb*)
                    printf 'The push refers to repository [ghcr.io/hardimpactdev/orbit-reverb]\n'
                    printf 'candidate: digest: %s size: 4287\n' "${ORBIT_TEST_REVERB_DIGEST}"
                    ;;
                *orbit-frankenphp*)
                    printf 'The push refers to repository [ghcr.io/hardimpactdev/orbit-frankenphp]\n'
                    printf 'candidate: digest: %s size: 4287\n' "${ORBIT_TEST_FRANKENPHP_DIGEST}"
                    ;;
                *)
                    printf 'The push refers to repository [ghcr.io/hardimpactdev/orbit-gateway]\n'
                    printf 'candidate: digest: %s size: 4287\n' "${ORBIT_TEST_GATEWAY_DIGEST}"
                    ;;
            esac
            exit 0
        fi
        if [ "$1" = 'save' ]; then
            output=''
            image=''
            shift
            while [ "$#" -gt 0 ]; do
                case "$1" in
                    -o)
                        shift
                        output="$1"
                        ;;
                    *)
                        image="$1"
                        ;;
                esac
                shift || true
            done
            [ -n "$output" ] && [ -n "$image" ] || exit 1
            tmp="$(mktemp -d)"
            printf '[{"Config":"config.json","RepoTags":["%s"],"Layers":[]}]\n' "$image" > "${tmp}/manifest.json"
            tar -C "$tmp" -cf "$output" manifest.json
            rm -rf "$tmp"
            exit 0
        fi
        exit 1
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'gh', body: <<<'BASH'
        printf 'gh %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        case "$1 $2" in
            'auth status') exit 0 ;;
            'auth token') printf 'stub-ghcr-token\n' ;;
            'api user') printf 'stub-user\n' ;;
            *) exit 1 ;;
        esac
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-version', body: <<<'BASH'
        printf 'orbit-version %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        printf '0.1.200\n'
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-gateway-artisan', body: <<<'BASH'
        printf 'orbit-gateway-artisan %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        for arg in "$@"; do
            case "$arg" in
                *'artifacts.base_url'*)
                    printf 'https://s3.example.test/orbit\n'
                    exit 0
                    ;;
                *'filesystems.disks.orbit-artifacts'*)
                    printf 'yes\n'
                    exit 0
                    ;;
            esac
        done
        exit 0
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-build-cli-binary', body: <<<'BASH'
        printf 'orbit-build-cli-binary %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        root="${ORBIT_RELEASE_CANDIDATE_ROOT:?}"
        case "$1 $2" in
            'mac arm')
                mkdir -p "${root}/apps/cli/builds/dist/mac"
                printf 'stub-mac-arm-binary\n' > "${root}/apps/cli/builds/dist/mac/mac-arm"
                ;;
            'linux x64')
                mkdir -p "${root}/apps/cli/builds/dist/linux"
                printf 'stub-linux-x64-binary\n' > "${root}/apps/cli/builds/dist/linux/linux-x64"
                ;;
            *) exit 1 ;;
        esac
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-build-agent-binary', body: <<<'BASH'
        printf 'orbit-build-agent-binary %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        root="${ORBIT_RELEASE_CANDIDATE_ROOT:?}"
        case "$1 $2" in
            'linux x64')
                mkdir -p "${root}/apps/agent/builds/dist/linux"
                printf 'stub-agent-linux-x64-binary\n' > "${root}/apps/agent/builds/dist/linux/linux-x64"
                ;;
            'mac arm')
                mkdir -p "${root}/apps/agent/builds/dist/mac"
                printf 'stub-agent-macos-arm64-binary\n' > "${root}/apps/agent/builds/dist/mac/mac-arm"
                ;;
            *) exit 1 ;;
        esac
        BASH);

    release_candidate_write_stub(binDir: "{$root}/bin", name: 'orbit-release-manifest', body: <<<'BASH'
        printf 'orbit-release-manifest %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
        output=''
        for arg in "$@"; do
            case "$arg" in
                --output=*) output="${arg#--output=}" ;;
            esac
        done
        [ -n "$output" ] || exit 1
        printf '{"schema_version":1,"source":"topology-candidate","stub":true}\n' > "$output"
        BASH);

    return $root;
}

function release_candidate_write_owned_inputs(string $root): void
{
    $files = [
        'VERSION' => "0.1.200\n",
        'docker/orbit-gateway/Dockerfile' => "FROM scratch\n",
        'docker/orbit-reverb/Dockerfile' => "FROM ubuntu:26.04\n",
        'docker/orbit-frankenphp/Dockerfile' => "FROM dunglas/frankenphp:1-php8.5-bookworm\n",
        'apps/gateway/artisan' => "<?php\n",
        'apps/gateway/composer.json' => "{}\n",
        'apps/gateway/composer.lock' => "{}\n",
        'apps/gateway/.env.example' => "APP_KEY=\n",
        'apps/gateway/app/.keep' => "keep\n",
        'apps/gateway/bootstrap/.keep' => "keep\n",
        'apps/gateway/config/.keep' => "keep\n",
        'apps/gateway/database/.keep' => "keep\n",
        'apps/gateway/public/.keep' => "keep\n",
        'apps/gateway/resources/css/.keep' => "keep\n",
        'apps/gateway/resources/js/.keep' => "keep\n",
        'apps/gateway/resources/views/.keep' => "keep\n",
        'apps/gateway/routes/.keep' => "keep\n",
        'apps/reverb/composer.lock' => "{}\n",
        'bin/install-orbit' => "#!/bin/sh\n",
        'packages/core/composer.json' => "{}\n",
        'packages/core/composer.lock' => "{}\n",
        'packages/core/src/.keep' => "keep\n",
        'packages/core/resources/php-cli/artifact-catalog.json' => "{}\n",
        'packages/sdk/composer.json' => "{}\n",
        'packages/sdk/composer.lock' => "{}\n",
        'packages/sdk/src/.keep' => "keep\n",
    ];

    foreach ($files as $relative => $content) {
        $path = "{$root}/{$relative}";
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents($path, $content);
    }
}

function release_candidate_write_stub(string $binDir, string $name, string $body): void
{
    $path = "{$binDir}/{$name}";

    file_put_contents($path, "#!/usr/bin/env bash\nset -euo pipefail\n{$body}\n");
    chmod($path, 0o755);
}

/**
 * @param  array<string, string|false>  $overrides
 *
 * @return array<string, string|false>
 */
function release_candidate_process_env(string $root, array $overrides = []): array
{
    return array_merge([
        'PATH' => "{$root}/bin:".getenv('PATH'),
        'ORBIT_RELEASE_CANDIDATE_ROOT' => $root,
        'ORBIT_RELEASE_ENV_FILE' => "{$root}/release.env",
        'ORBIT_PRIMARY_CHECKOUT' => $root,
        'HOME' => "{$root}/home",
        'STUB_LOG' => "{$root}/stub.log",
        'ORBIT_TEST_HEAD_COMMIT' => str_repeat('a', 40),
        'ORBIT_TEST_ORIGIN_MAIN_COMMIT' => str_repeat('a', 40),
        'ORBIT_TEST_GATEWAY_DIGEST' => 'sha256:'.str_repeat('ab', times: 32),
        'ORBIT_TEST_REVERB_DIGEST' => 'sha256:'.str_repeat('cd', times: 32),
        'ORBIT_TEST_FRANKENPHP_DIGEST' => 'sha256:'.str_repeat('ef', times: 32),
        'ORBIT_RELEASE_CANDIDATE_CHANNEL' => false,
    ], $overrides);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string|false>  $env
 */
function release_candidate_process(array $arguments, array $env, ?string $cwd = null): Process
{
    $process = new Process(
        [repo_path('bin/orbit-release-candidate'), ...$arguments],
        $cwd ?? repo_path(),
        $env,
    );

    $process->run();

    return $process;
}

/**
 * Writes a durable release-candidate state directory the way `build` records
 * it, so `env` and `verify` behavior can be exercised without a build run.
 *
 * @param  array<string, string>  $overrides
 */
function release_candidate_write_state(
    string $root,
    string $buildId,
    array $overrides = [],
    bool $pointLatest = false,
): string {
    $stateDir = "{$root}/.orbit/release-candidates/{$buildId}";

    mkdir($stateDir, recursive: true);

    file_put_contents("{$stateDir}/orbit-linux-x64", "linux-artifact-{$buildId}\n");
    file_put_contents("{$stateDir}/orbit-macos-arm64", "mac-artifact-{$buildId}\n");
    file_put_contents("{$stateDir}/orbit-agent-linux-x64", "agent-linux-artifact-{$buildId}\n");
    file_put_contents("{$stateDir}/orbit-agent-macos-arm64", "agent-mac-artifact-{$buildId}\n");
    file_put_contents("{$stateDir}/orbit-release-manifest.candidate.json", "{\"stub\":true}\n");
    file_put_contents("{$stateDir}/orbit-frankenphp-linux-amd64.tar", "frankenphp-image-artifact-{$buildId}\n");
    file_put_contents("{$stateDir}/orbit-reverb-linux-amd64.tar", "reverb-image-artifact-{$buildId}\n");
    file_put_contents("{$stateDir}/gateway-image-push.log", "candidate: digest: sha256:stub size: 1\n");

    $values = array_merge([
        'version' => '9.9.9',
        'build_id' => $buildId,
        'commit' => str_repeat('a', 40),
        'candidate_image' => "ghcr.io/hardimpactdev/orbit-gateway:9.9.9-candidate-{$buildId}",
        'candidate_dir' => $stateDir,
        'candidate_prefix' => "candidates/{$buildId}",
        'candidate_channel' => 'live-test',
        'candidate_asset_base_url' => "https://s3.example.test/orbit/candidates/{$buildId}",
        'gateway_digest' => 'sha256:'.str_repeat('ab', times: 32),
        'candidate_reverb_image' => "ghcr.io/hardimpactdev/orbit-reverb:9.9.9-candidate-{$buildId}",
        'reverb_digest' => 'sha256:'.str_repeat('cd', times: 32),
        'candidate_frankenphp_image' => "ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-{$buildId}",
        'stable_frankenphp_image' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        'frankenphp_digest' => 'sha256:'.str_repeat('ef', times: 32),
        'candidate_channel_manifest_url' => 'https://s3.example.test/orbit/channels/live-test/orbit-release-manifest.json',
        'sha256_linux_amd64' => (string) hash_file('sha256', "{$stateDir}/orbit-linux-x64"),
        'sha256_darwin_arm64' => (string) hash_file('sha256', "{$stateDir}/orbit-macos-arm64"),
        'sha256_agent_linux_amd64' => (string) hash_file('sha256', "{$stateDir}/orbit-agent-linux-x64"),
        'sha256_agent_darwin_arm64' => (string) hash_file('sha256', "{$stateDir}/orbit-agent-macos-arm64"),
        'sha256_frankenphp_linux_amd64' => (string) hash_file(
            'sha256',
            "{$stateDir}/orbit-frankenphp-linux-amd64.tar",
        ),
        'sha256_reverb_linux_amd64' => (string) hash_file('sha256', "{$stateDir}/orbit-reverb-linux-amd64.tar"),
    ], $overrides);

    $lines = '';

    foreach ($values as $key => $value) {
        $lines .= "{$key}={$value}\n";
    }

    file_put_contents("{$stateDir}/candidate.env", $lines);

    if ($pointLatest) {
        file_put_contents("{$root}/.orbit/release-candidates/latest", "{$buildId}\n");
    }

    return $stateDir;
}

function release_candidate_latest_build_id(string $root): string
{
    $pointer = "{$root}/.orbit/release-candidates/latest";

    expect($pointer)->toBeFile();

    $buildId = trim((string) file_get_contents($pointer));

    expect($buildId)->toMatch('/^\d{8}T\d{6}Z-[0-9a-f]+$/');

    return $buildId;
}

/**
 * @param  array<string, string|false>  $env
 */
function release_candidate_accept(string $root, array $env, string $buildId): void
{
    $process = release_candidate_process(
        arguments: ['promote-runtime', "--build-id={$buildId}", '--accepted'],
        env: [
            ...$env,
            'DOCKER_CONFIG' => 'home/.docker',
            'ORBIT_TEST_REQUIRE_BUILDX_PLUGIN' => '1',
        ],
        cwd: $root,
    );

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
}

/**
 * @return list<string>
 */
function release_candidate_archive_repo_tags(string $path): array
{
    $process = new Process(['tar', '-xOf', $path, 'manifest.json']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    /** @var list<array{RepoTags?: list<string>}> $manifest */
    $manifest = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    return $manifest[0]['RepoTags'] ?? [];
}

/**
 * @param  array<string, string>  $manifestOverrides
 */
function release_candidate_write_native_bundle(
    string $root,
    string $commit,
    string $version,
    array $manifestOverrides = [],
): string {
    $dir = "{$root}/native-assets";

    mkdir($dir, recursive: true);
    file_put_contents("{$dir}/orbit-agent-macos-arm64", "imported-agent-{$commit}\n");
    chmod("{$dir}/orbit-agent-macos-arm64", 0o755);

    $values = array_merge([
        'schema' => '1',
        'commit' => $commit,
        'version' => $version,
        'builder_os' => 'Darwin',
        'builder_arch' => 'arm64',
        'sha256_agent_darwin_arm64' => (string) hash_file('sha256', "{$dir}/orbit-agent-macos-arm64"),
    ], $manifestOverrides);

    $lines = '';

    foreach ($values as $key => $value) {
        $lines .= "{$key}={$value}\n";
    }

    file_put_contents("{$dir}/native-assets.env", $lines);

    return $dir;
}

/**
 * @return array<string, string>
 */
function release_candidate_parse_state(string $path): array
{
    expect($path)->toBeFile();

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines === false ? [] : $lines as $line) {
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = $value;
    }

    return $values;
}
