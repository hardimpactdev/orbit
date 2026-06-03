<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EProvisionCheckpointManifest;
use App\E2E\Support\E2EProvisionCheckpointStore;
use App\E2E\Support\E2EProvisionFingerprint;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('does not invalidate provision checkpoints for assertion-only e2e test changes', function (): void {
    $root = makeProvisionFingerprintFixture();

    try {
        $before = E2EProvisionFingerprint::fromRoot(
            root: $root,
            kind: E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            environment: ['ORBIT_E2E_HOST' => 'beast'],
            baseImageIdentity: 'orbit-base@sha256:base',
        );

        file_put_contents("{$root}/apps/e2e/tests/Feature/Commands/AppAssertionTest.php", "<?php\nit('changes only assertions', fn () => expect(true)->toBeTrue());\n");

        $after = E2EProvisionFingerprint::fromRoot(
            root: $root,
            kind: E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            environment: ['ORBIT_E2E_HOST' => 'beast'],
            baseImageIdentity: 'orbit-base@sha256:base',
        );

        expect($after['fingerprints'])->toBe($before['fingerprints'])
            ->and($after['role_fingerprints'])->toBe($before['role_fingerprints']);
    } finally {
        remove_directory($root);
    }
});

it('invalidates every role checkpoint when provision support changes', function (): void {
    $root = makeProvisionFingerprintFixture();

    try {
        $before = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

        file_put_contents("{$root}/bin/e2e-provision-node", "#!/usr/bin/env bash\necho changed\n");

        $after = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

        foreach (['operator', 'gateway', 'dev', 'prod', 'agent'] as $role) {
            expect($after['role_fingerprints'][$role])->not->toBe($before['role_fingerprints'][$role]);
        }
    } finally {
        remove_directory($root);
    }
});

it('invalidates all CLI-consuming roles when the CLI artifact changes', function (): void {
    $root = makeProvisionFingerprintFixture();

    try {
        $before = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

        file_put_contents("{$root}/apps/cli/orbit", "#!/usr/bin/env php\n<?php echo 'changed';\n");

        $after = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

        foreach (['operator', 'gateway', 'dev', 'prod', 'agent'] as $role) {
            expect($after['role_fingerprints'][$role])->not->toBe($before['role_fingerprints'][$role]);
        }
    } finally {
        remove_directory($root);
    }
});

it('invalidates gateway and gateway-state-dependent roles when the gateway artifact changes', function (): void {
    $root = makeProvisionFingerprintFixture();

    try {
        $before = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

        file_put_contents("{$root}/apps/gateway/app/GatewayRuntime.php", "<?php\nreturn 'changed';\n");

        $after = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

        expect($after['role_fingerprints']['operator'])->toBe($before['role_fingerprints']['operator']);

        foreach (['gateway', 'dev', 'prod', 'agent'] as $role) {
            expect($after['role_fingerprints'][$role])->not->toBe($before['role_fingerprints'][$role]);
        }
    } finally {
        remove_directory($root);
    }
});

it('invalidates all role checkpoints when the base image identity or role dag changes', function (): void {
    $root = makeProvisionFingerprintFixture();

    try {
        $before = E2EProvisionFingerprint::fromRoot(
            root: $root,
            kind: E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            baseImageIdentity: 'orbit-base@sha256:old',
        );

        $baseChanged = E2EProvisionFingerprint::fromRoot(
            root: $root,
            kind: E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            baseImageIdentity: 'orbit-base@sha256:new',
        );
        $dagChanged = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);

        foreach (['operator', 'gateway', 'dev', 'prod', 'agent'] as $role) {
            expect($baseChanged['role_fingerprints'][$role])->not->toBe($before['role_fingerprints'][$role])
                ->and($dagChanged['role_fingerprints'][$role])->not->toBe($before['role_fingerprints'][$role]);
        }
    } finally {
        remove_directory($root);
    }
});

it('does not invalidate role checkpoints when runtime archive bytes are regenerated from unchanged source inputs', function (): void {
    $root = makeProvisionFingerprintFixture();
    $firstBundle = make_temp_directory('provision-runtime-archives-first');
    $secondBundle = make_temp_directory('provision-runtime-archives-second');

    try {
        file_put_contents("{$firstBundle}/orbit-binary", 'first generated binary bytes');
        file_put_contents("{$secondBundle}/orbit-binary", 'second generated binary bytes');

        $before = E2EProvisionFingerprint::fromRoot(
            root: $root,
            kind: E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            bundleDirectory: $firstBundle,
        );
        $after = E2EProvisionFingerprint::fromRoot(
            root: $root,
            kind: E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            bundleDirectory: $secondBundle,
        );

        expect($after['fingerprints']['runtime_archives'])->not->toBe($before['fingerprints']['runtime_archives'])
            ->and($after['role_fingerprints'])->toBe($before['role_fingerprints']);
    } finally {
        remove_directory($root);
        remove_directory($firstBundle);
        remove_directory($secondBundle);
    }
});

it('does not invalidate role checkpoints when CLI build output changes', function (): void {
    $root = makeProvisionFingerprintFixture();

    try {
        $before = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

        mkdir("{$root}/apps/cli/build/phpstan", 0777, true);
        mkdir("{$root}/apps/cli/builds/dist/linux", 0777, true);
        file_put_contents("{$root}/apps/cli/build/phpstan/resultCache.php", "<?php\nreturn ['generated' => 'changed'];\n");
        file_put_contents("{$root}/apps/cli/builds/orbit.phar", 'changed phar bytes');
        file_put_contents("{$root}/apps/cli/builds/dist/linux/linux-x64", 'changed native binary bytes');

        $after = E2EProvisionFingerprint::fromRoot($root, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);

        expect($after['fingerprints']['cli_artifact'])->toBe($before['fingerprints']['cli_artifact'])
            ->and($after['role_fingerprints'])->toBe($before['role_fingerprints']);
    } finally {
        remove_directory($root);
    }
});

it('requires matching manifest fingerprints and live snapshots before reusing checkpoints', function (): void {
    $fingerprint = [
        'schema_version' => 1,
        'topology_kind' => 'operator_gateway_app-dev_app-prod_agent_websocket',
        'role_dag' => ['operator' => [], 'gateway' => ['operator']],
        'fingerprints' => ['global' => 'global-current'],
        'role_fingerprints' => [
            'operator' => 'operator-current',
            'gateway' => 'gateway-current',
        ],
    ];
    $manifest = E2EProvisionCheckpointManifest::create(
        kind: E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
        fingerprint: $fingerprint,
        checkpoints: [
            ['role' => 'operator', 'name' => 'orbit-template-operator-base', 'snapshot' => 'clean-full-base'],
            ['role' => 'gateway', 'name' => 'orbit-template-gateway-base', 'snapshot' => 'clean-full-base'],
        ],
        complete: false,
    );

    expect(E2EProvisionCheckpointManifest::validRoles(
        manifest: $manifest,
        currentFingerprint: [
            ...$fingerprint,
            'role_fingerprints' => [
                'operator' => 'operator-current',
                'gateway' => 'gateway-stale',
            ],
        ],
        snapshotExists: fn (string $template, string $snapshot): bool => true,
    ))->toBe(['operator'])
        ->and(E2EProvisionCheckpointManifest::validRoles(
            manifest: $manifest,
            currentFingerprint: $fingerprint,
            snapshotExists: fn (string $template, string $snapshot): bool => $template !== 'orbit-template-gateway-base',
        ))->toBe(['operator']);
});

it('keeps checkpoint manifests compact by omitting raw fingerprint input file maps', function (): void {
    $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
    $manifest = E2EProvisionCheckpointManifest::create(
        kind: $kind,
        fingerprint: [
            'schema_version' => 1,
            'topology_kind' => $kind->value,
            'role_dag' => ['operator' => []],
            'fingerprints' => ['global' => 'global-current'],
            'role_fingerprints' => ['operator' => 'operator-current'],
            'inputs' => [
                'source' => [
                    'provision' => [
                        'files' => array_fill_keys(
                            array_map(fn (int $index): string => "apps/e2e/app/File{$index}.php", range(1, 500)),
                            str_repeat('a', 64),
                        ),
                    ],
                ],
            ],
        ],
        checkpoints: [
            ['role' => 'operator', 'name' => 'orbit-template-operator-base', 'snapshot' => 'clean-full-base'],
        ],
        complete: false,
    );

    expect($manifest)->not->toHaveKey('inputs')
        ->and(strlen(json_encode($manifest, JSON_THROW_ON_ERROR)))->toBeLessThan(2000);
});

it('retries checkpoint manifest writes after a transient host transport failure', function (): void {
    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
    $attempts = 0;
    $paths = [];

    $host->shouldReceive('writeTextFile')
        ->twice()
        ->andReturnUsing(function (string $path, string $contents) use (&$attempts, &$paths): ProcessResult {
            $attempts++;
            $paths[] = $path;

            expect($contents)->toContain('"schema_version"');

            return checkpointProcessResult(
                successful: $attempts === 2,
                errorOutput: $attempts === 1 ? 'mux_client_request_session: write packet: Broken pipe' : '',
            );
        });

    $store = new E2EProvisionCheckpointStore($host, writeAttempts: 2, writeRetryDelayMilliseconds: 0);
    $store->write(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket, ['schema_version' => 1]);

    expect($attempts)->toBe(2)
        ->and($paths[0])->toContain('.cache/orbit-e2e/provision-checkpoints/base/operator_gateway_app-dev_app-prod_agent_websocket.json');
});

it('retries only missing downstream roles from a partial prepared checkpoint', function (): void {
    $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
    $fingerprint = [
        'schema_version' => 1,
        'topology_kind' => $kind->value,
        'role_dag' => [
            'operator' => [],
            'gateway' => ['operator'],
            'dev' => ['gateway'],
            'prod' => ['gateway'],
            'agent' => ['gateway'],
        ],
        'fingerprints' => ['global' => 'global-current'],
        'role_fingerprints' => [
            'operator' => 'operator-current',
            'gateway' => 'gateway-current',
            'dev' => 'dev-current',
            'prod' => 'prod-current',
            'agent' => 'agent-current',
        ],
    ];
    $manifest = E2EProvisionCheckpointManifest::create(
        kind: $kind,
        fingerprint: $fingerprint,
        checkpoints: [
            ['role' => 'operator', 'name' => 'orbit-template-operator-base', 'snapshot' => 'clean-full-base'],
            ['role' => 'gateway', 'name' => 'orbit-template-gateway-base', 'snapshot' => 'clean-full-base'],
            ['role' => 'dev', 'name' => 'orbit-template-app-dev-base', 'snapshot' => 'clean-full-base'],
            ['role' => 'agent', 'name' => 'orbit-template-agent-base', 'snapshot' => 'clean-full-base'],
        ],
        complete: false,
    );

    $validCheckpoints = E2EProvisionCheckpointManifest::validCheckpoints(
        manifest: $manifest,
        currentFingerprint: $fingerprint,
        snapshotExists: fn (string $template, string $snapshot): bool => true,
    );

    expect(array_column($validCheckpoints, 'role'))->toBe(['operator', 'gateway', 'dev', 'agent'])
        ->and(E2EProvisionCheckpointManifest::missingRoles($kind, $validCheckpoints))->toBe(['prod']);
});

it('keeps serving assertions out of the provision group', function (): void {
    $contents = file_get_contents(base_path('tests/Feature/Commands/Ephemeral/AppServingTest.php'));

    expect($contents)->toBeString()
        ->and($contents)->not->toContain('e2e-provision')
        ->and($contents)->toContain('E2ETopologyKind::OperatorGatewayAppdev')
        ->and($contents)->toContain('appServingAssertDoctorHealthy($topology, \'tool\')')
        ->and($contents)->toContain('$keyOption = $key === null')
        ->and($contents)->toContain('HOME=/home/orbit /usr/local/bin/laravel --version')
        ->and($contents)->not->toContain('E2ETopologyKind::OperatorGatewayAppdevWebsocket')
        ->and($contents)->not->toContain('e2e-feature-operator_gateway_app-dev_websocket');
});

function makeProvisionFingerprintFixture(): string
{
    $root = make_temp_directory('provision-fingerprint');
    $files = [
        'composer.json' => '{"scripts":{"test:e2e:provision:incus":"pest --group=e2e-provision"}}',
        'composer.lock' => '{}',
        'apps/cli/orbit' => "#!/usr/bin/env php\n<?php echo 'orbit';\n",
        'apps/cli/composer.json' => '{"name":"orbit/cli"}',
        'apps/cli/composer.lock' => '{}',
        'packages/core/src/Core.php' => "<?php\nreturn 'core';\n",
        'apps/gateway/app/GatewayRuntime.php' => "<?php\nreturn 'gateway';\n",
        'apps/gateway/composer.json' => '{"name":"orbit/gateway"}',
        'apps/gateway/composer.lock' => '{}',
        'apps/e2e/app/E2E/Support/IncusTopologyBuilder.php' => "<?php\nreturn 'builder';\n",
        'apps/e2e/app/Console/Commands/E2EPrepareTopologyCommand.php' => "<?php\nreturn 'command';\n",
        'apps/e2e/tests/Feature/Commands/AppAssertionTest.php' => "<?php\nit('asserts', fn () => expect(true)->toBeTrue());\n",
        'bin/install-orbit' => "#!/usr/bin/env bash\necho install\n",
        'bin/e2e-provision-node' => "#!/usr/bin/env bash\necho provision\n",
        'bin/_e2e-deps.sh' => "#!/usr/bin/env bash\necho deps\n",
    ];

    foreach ($files as $path => $contents) {
        $absolute = "{$root}/{$path}";

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }

        file_put_contents($absolute, $contents);
    }

    return $root;
}

function checkpointProcessResult(bool $successful, string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}
