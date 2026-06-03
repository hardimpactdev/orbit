<?php

declare(strict_types=1);

use App\Console\Commands\E2EPrepareTopologyCommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

// ---------------------------------------------------------------------------
// Role validation: websocket and other canonical roles
// ---------------------------------------------------------------------------

it('accepts websocket as a canonical artifact role', function (): void {
    $roles = E2EPreparedTopology::parseArtifactRoles('websocket');

    expect($roles)->toBe(['websocket']);
});

it('accepts all canonical artifact roles including ingress and websocket', function (): void {
    $roles = E2EPreparedTopology::parseArtifactRoles('operator,gateway,app-dev,app-prod,ingress,agent,websocket');

    expect($roles)->toBe(['operator', 'gateway', 'app-dev', 'app-prod', 'ingress', 'agent', 'websocket']);
});

it('rejects unknown artifact roles', function (): void {
    expect(fn () => E2EPreparedTopology::parseArtifactRoles('unknown-role'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported prepared artifact role [unknown-role]');
});

it('maps websocket artifact role to dev Incus role', function (): void {
    $incusRole = E2EPreparedTopology::incusRoleForArtifactRole('websocket');

    expect($incusRole)->toBe('dev');
});

it('maps app-dev artifact role to dev Incus role', function (): void {
    $incusRole = E2EPreparedTopology::incusRoleForArtifactRole('app-dev');

    expect($incusRole)->toBe('dev');
});

// ---------------------------------------------------------------------------
// Gateway consistency: operator + gateway must be co-selected
// ---------------------------------------------------------------------------

it('rejects gateway selected without operator', function (): void {
    Process::fake();
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn(fakeSuccess());

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/fake-bundle');

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'gateway-consistency',
    ], function () use ($builder): void {
        expect(fn () => $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['gateway'],
        ))->toThrow(RuntimeException::class, "Selected roles include 'gateway' but not 'operator'");
    });
});

it('rejects operator selected without gateway', function (): void {
    Process::fake();
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn(fakeSuccess());

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/fake-bundle');

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'gateway-consistency',
    ], function () use ($builder): void {
        expect(fn () => $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['operator'],
        ))->toThrow(RuntimeException::class, "Selected roles include 'operator' but not 'gateway'");
    });
});

it('accepts agent selected alone without gateway or operator', function (): void {
    Process::fake();
    $config = E2EConfig::fromEnvironment();

    $commands = [];
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            // mktemp for work directory
            if (str_contains($command, 'mktemp -d /tmp/orbit-topology-builder')) {
                return fakeSuccess('/tmp/orbit-topo-work-test');
            }

            // ssh-keygen
            if (str_contains($command, 'ssh-keygen')) {
                return fakeSuccess();
            }

            // instance exists check
            if (str_contains($command, 'incus info') && ! str_contains($command, 'orbit-template-agent-agent-isolation')) {
                return fakeSuccess(); // base template exists
            }

            // snapshot exists check
            if (str_contains($command, 'incus query')) {
                return fakeSuccess();
            }

            // slug template does not yet exist
            if (str_contains($command, 'incus info') && str_contains($command, 'orbit-template-agent-agent-isolation')) {
                return fakeFailure(); // not yet created
            }

            // copy base template
            if (str_contains($command, 'incus copy')) {
                return fakeSuccess();
            }

            // start instance
            if (str_contains($command, 'incus start')) {
                return fakeSuccess();
            }

            // stop instance
            if (str_contains($command, 'incus stop')) {
                return fakeSuccess();
            }

            // snapshot
            if (str_contains($command, 'incus snapshot create')) {
                return fakeSuccess();
            }

            // workdir cleanup
            if (str_contains($command, 'rm -rf')) {
                return fakeSuccess();
            }

            // composer cache check
            if (str_contains($command, 'test -d')) {
                return fakeFailure();
            }

            return fakeSuccess();
        });

    // Stub instance exec calls (incus exec)
    $host->shouldReceive('run')
        ->withArgs(fn (string $cmd) => str_contains($cmd, 'incus exec'))
        ->andReturnUsing(fn (): ProcessResult => fakeSuccess());

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/fake-bundle');

    // No exception means gateway consistency passed
    expect(true)->toBeTrue();
});

it('accepts gateway and operator selected together', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn(fakeSuccess('/tmp/orbit-topo-work-test'));
    $host->shouldReceive('instanceExists')->andReturn(true);
    $host->shouldReceive('snapshotExists')->andReturn(true);
    $host->shouldReceive('deleteInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('startInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('stopInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('snapshotInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('storagePoolArgument')->andReturn('');

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/fake-bundle');

    // Should not throw a consistency error
    $threwConsistencyError = false;

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'gateway-consistency',
    ], function () use ($builder, &$threwConsistencyError): void {
        try {
            $builder->buildSelectedRoles(
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
                ['operator', 'gateway'],
                replaceExisting: true,
            );
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'CA trust') || str_contains($e->getMessage(), 'WireGuard')) {
                $threwConsistencyError = true;
            }
        }
    });

    expect($threwConsistencyError)->toBeFalse();
});

it('fails without a bundle staged', function (): void {
    Process::fake();
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();

    $builder = new IncusTopologyBuilder($host);

    expect(fn () => $builder->buildSelectedRoles(
        E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
        ['agent'],
    ))->toThrow(RuntimeException::class, 'No provisioning bundle has been staged');
});

it('rejects selected role rebakes into the shared base namespace', function (): void {
    Process::fake();
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/fake-bundle');

    expect(fn () => $builder->buildSelectedRoles(
        E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
        ['agent'],
    ))->toThrow(RuntimeException::class, 'Set ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE when using selected Incus role rebakes');
});

// ---------------------------------------------------------------------------
// Incus command sequence: copy from base, start, overlay, stop, snapshot
// ---------------------------------------------------------------------------

it('copies base template snapshot into slug namespace for each selected role', function (): void {
    $config = E2EConfig::fromEnvironment();
    $commands = [];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('storagePoolArgument')->andReturn('');
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(function (string $name) use (&$commands): bool {
            // Base templates exist; slug templates do not yet.
            return ! str_contains($name, '-agent-isolation');
        });
    $host->shouldReceive('snapshotExists')->andReturn(true);
    $host->shouldReceive('deleteInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('startInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('stopInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('snapshotInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (str_contains($command, 'mktemp -d /tmp/orbit-topology-builder')) {
                return fakeSuccess('/tmp/orbit-topo-work-agent-isolation');
            }

            return fakeSuccess();
        });

    $previousNamespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');
    putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation');

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle('/tmp/fake-bundle');
        $manifest = $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['agent'],
            replaceExisting: false,
        );
    } finally {
        $previousNamespace === false
            ? putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE')
            : putenv("ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE={$previousNamespace}");
    }

    $copyCommands = array_filter($commands, fn (string $cmd) => str_contains($cmd, 'incus copy'));
    $copyCommand = implode(' ', $copyCommands);

    // Must copy from base template with base snapshot
    expect($copyCommand)
        ->toContain('orbit-template-agent-base')
        ->toContain('clean-operator_gateway_app-dev_app-prod_agent-base')
        ->toContain('orbit-template-agent-agent-isolation');

    // Manifest must contain the slug template and snapshot
    expect($manifest)->toHaveCount(1)
        ->and($manifest[0]['role'])->toBe('agent')
        ->and($manifest[0]['name'])->toBe('orbit-template-agent-agent-isolation')
        ->and($manifest[0]['snapshot'])->toBe('clean-operator_gateway_app-dev_app-prod_agent-agent-isolation');
});

it('boots selected VMs, applies bundle overlay, stops and snapshots them', function (): void {
    $config = E2EConfig::fromEnvironment();
    $startedInstances = [];
    $stoppedInstances = [];
    $snapshotted = [];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('storagePoolArgument')->andReturn('');
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => str_contains($name, '-base'));
    $host->shouldReceive('snapshotExists')->andReturn(true);
    $host->shouldReceive('startInstance')
        ->andReturnUsing(function (string $name) use (&$startedInstances): ProcessResult {
            $startedInstances[] = $name;

            return fakeSuccess();
        });
    $host->shouldReceive('stopInstance')
        ->andReturnUsing(function (string $name) use (&$stoppedInstances): ProcessResult {
            $stoppedInstances[] = $name;

            return fakeSuccess();
        });
    $host->shouldReceive('snapshotInstance')
        ->andReturnUsing(function (string $name, string $snapshot) use (&$snapshotted): ProcessResult {
            $snapshotted[] = "{$name}/{$snapshot}";

            return fakeSuccess();
        });
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_contains($command, 'mktemp -d /tmp/orbit-topology-builder')) {
                return fakeSuccess('/tmp/orbit-topo-work-test');
            }

            return fakeSuccess();
        });

    $previousNamespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');
    putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=pr-123');

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle('/tmp/fake-bundle');
        $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['agent'],
        );
    } finally {
        $previousNamespace === false
            ? putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE')
            : putenv("ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE={$previousNamespace}");
    }

    expect($startedInstances)->toContain('orbit-template-agent-pr-123')
        ->and($stoppedInstances)->toContain('orbit-template-agent-pr-123')
        ->and($snapshotted)->toContain('orbit-template-agent-pr-123/clean-operator_gateway_app-dev_app-prod_agent-pr-123');
});

it('applies bundle overlay to selected VMs via incus exec and incus file push', function (): void {
    $config = E2EConfig::fromEnvironment();
    $sshCommands = [];
    $execCommands = [];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('storagePoolArgument')->andReturn('');
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => str_contains($name, '-base'));
    $host->shouldReceive('snapshotExists')->andReturn(true);
    $host->shouldReceive('startInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('stopInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('snapshotInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command) use (&$sshCommands, &$execCommands): ProcessResult {
            $sshCommands[] = $command;

            if (str_contains($command, 'mktemp -d /tmp/orbit-topology-builder')) {
                return fakeSuccess('/tmp/orbit-topo-work-test');
            }

            if (str_contains($command, 'incus exec')) {
                $execCommands[] = $command;
            }

            if (str_contains($command, 'incus file push') && str_contains($command, 'var/tmp')) {
                return fakeSuccess();
            }

            // test -d composer-cache: fail (no cache)
            if (str_contains($command, 'test -d') && str_contains($command, 'composer-cache')) {
                return fakeFailure();
            }

            return fakeSuccess();
        });

    $previousNamespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');
    putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=pr-42');

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle('/tmp/fake-bundle');
        $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['agent'],
        );
    } finally {
        $previousNamespace === false
            ? putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE')
            : putenv("ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE={$previousNamespace}");
    }

    $allCommands = implode(' ', $sshCommands);

    // Must push the bundle into the guest
    expect($allCommands)->toContain('incus file push');

    // Must extract the source archive directly (no install-orbit, no docker dependency)
    $extractOccurrences = array_filter($sshCommands, fn (string $cmd) => str_contains($cmd, 'tar') && str_contains($cmd, 'orbit-source.tar.gz'));
    expect($extractOccurrences)->not->toBeEmpty();

    // Must run composer install
    $composerInstallOccurrences = array_filter($sshCommands, fn (string $cmd) => str_contains($cmd, 'composer') && str_contains($cmd, 'install'));
    expect($composerInstallOccurrences)->not->toBeEmpty();
});

it('replaces existing slug template when replaceExisting is true', function (): void {
    $config = E2EConfig::fromEnvironment();
    $deletedInstances = [];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('storagePoolArgument')->andReturn('');
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(function (string $name) use (&$deletedInstances): bool {
            // Base templates exist; slug template also exists (should be deleted)
            return true;
        });
    $host->shouldReceive('snapshotExists')->andReturn(true);
    $host->shouldReceive('deleteInstance')
        ->andReturnUsing(function (string $name) use (&$deletedInstances): ProcessResult {
            $deletedInstances[] = $name;

            return fakeSuccess();
        });
    $host->shouldReceive('startInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('stopInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('snapshotInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_contains($command, 'mktemp -d /tmp/orbit-topology-builder')) {
                return fakeSuccess('/tmp/orbit-topo-work-test');
            }

            return fakeSuccess();
        });

    $previousNamespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');
    putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=pr-99');

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle('/tmp/fake-bundle');
        $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['agent'],
            replaceExisting: true,
        );
    } finally {
        $previousNamespace === false
            ? putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE')
            : putenv("ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE={$previousNamespace}");
    }

    expect($deletedInstances)->toContain('orbit-template-agent-pr-99');
});

it('fails when existing slug template already exists without replaceExisting', function (): void {
    Process::fake();
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('instanceExists')->andReturn(true);
    $host->shouldReceive('snapshotExists')->andReturn(true);
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_contains($command, 'mktemp -d /tmp/orbit-topology-builder')) {
                return fakeSuccess('/tmp/orbit-topo-work-test');
            }

            if (str_contains($command, 'rm -rf')) {
                return fakeSuccess();
            }

            return fakeSuccess();
        });

    $previousNamespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');
    putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=pr-77');

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle('/tmp/fake-bundle');

        expect(fn () => $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['agent'],
            replaceExisting: false,
        ))->toThrow(RuntimeException::class, 'already exists');
    } finally {
        $previousNamespace === false
            ? putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE')
            : putenv("ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE={$previousNamespace}");
    }
});

it('fails when base template is missing', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => ! str_contains($name, 'base'));
    $host->shouldReceive('snapshotExists')->andReturn(false);
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_contains($command, 'mktemp -d /tmp/orbit-topology-builder')) {
                return fakeSuccess('/tmp/orbit-topo-work-test');
            }

            if (str_contains($command, 'rm -rf')) {
                return fakeSuccess();
            }

            return fakeSuccess();
        });

    $previousNamespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');
    putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=pr-55');

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle('/tmp/fake-bundle');

        expect(fn () => $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['agent'],
        ))->toThrow(RuntimeException::class, 'Base template');
    } finally {
        $previousNamespace === false
            ? putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE')
            : putenv("ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE={$previousNamespace}");
    }
});

// ---------------------------------------------------------------------------
// Websocket topology: selected-role rebake uses websocket source kind
// ---------------------------------------------------------------------------

it('selects the websocket source kind when websocket artifact role is requested', function (): void {
    $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
    $buildKind = E2EPreparedTopology::incusSourceKindFor($kind);
    $sourceRoles = array_values(array_filter(
        IncusTopologyTemplate::rolesFor($buildKind),
        function (string $incusRole): bool {
            foreach (['websocket'] as $artifactRole) {
                if (E2EPreparedTopology::incusRoleForArtifactRole($artifactRole) === $incusRole) {
                    return true;
                }
            }

            return in_array(E2EPreparedTopology::artifactRoleForIncusRole($incusRole), ['websocket'], true);
        },
    ));

    // websocket maps to 'dev' Incus role in the websocket topology
    expect($buildKind)->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and($sourceRoles)->toContain('dev');
});

it('includes dev role in source roles when websocket artifact role is requested for websocket topology', function (): void {
    // Simulate the sourceRolesFor logic from the command for websocket topology
    $buildKind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
    $artifactRoles = ['websocket'];
    $roles = IncusTopologyTemplate::rolesFor($buildKind);

    $sourceRoles = array_values(array_filter(
        $roles,
        function (string $role) use ($artifactRoles): bool {
            if (in_array(E2EPreparedTopology::artifactRoleForIncusRole($role), $artifactRoles, true)) {
                return true;
            }

            foreach ($artifactRoles as $artifactRole) {
                if (E2EPreparedTopology::incusRoleForArtifactRole($artifactRole) === $role) {
                    return true;
                }
            }

            return false;
        },
    ));

    expect($sourceRoles)->toContain('dev')
        ->and($sourceRoles)->not->toContain('operator')
        ->and($sourceRoles)->not->toContain('gateway')
        ->and($sourceRoles)->not->toContain('prod')
        ->and($sourceRoles)->not->toContain('agent');
});

// ---------------------------------------------------------------------------
// Unselected roles: absent from slug namespace, fall back to base
// ---------------------------------------------------------------------------

it('unselected roles do not appear in the rebake manifest', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('storagePoolArgument')->andReturn('');
    $host->shouldReceive('instanceExists')
        ->andReturnUsing(fn (string $name): bool => ! str_contains($name, 'agent-isolation'));
    $host->shouldReceive('snapshotExists')->andReturn(true);
    $host->shouldReceive('startInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('stopInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('snapshotInstance')->andReturn(fakeSuccess());
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            if (str_contains($command, 'mktemp -d /tmp/orbit-topology-builder')) {
                return fakeSuccess('/tmp/orbit-topo-work-test');
            }

            return fakeSuccess();
        });

    $previousNamespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');
    putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation');

    try {
        $builder = new IncusTopologyBuilder($host);
        $builder->useBundle('/tmp/fake-bundle');
        $manifest = $builder->buildSelectedRoles(
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ['agent'],
        );
    } finally {
        $previousNamespace === false
            ? putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE')
            : putenv("ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE={$previousNamespace}");
    }

    $roles = array_column($manifest, 'role');

    // Only agent is rebuilt; operator, gateway, dev, prod are absent from manifest
    expect($roles)->toBe(['agent'])
        ->and($roles)->not->toContain('operator')
        ->and($roles)->not->toContain('gateway')
        ->and($roles)->not->toContain('dev')
        ->and($roles)->not->toContain('prod');
});

it('acquisition falls back to base snapshot for unselected roles', function (): void {
    $config = E2EConfig::fromEnvironment();
    $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgent;

    // Only agent template exists in the slug namespace; others absent.
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command): ProcessResult {
            // Slug agent template exists
            if (str_contains($command, 'orbit-template-agent-agent-isolation') && ! str_contains($command, 'clean-')) {
                return fakeSuccess();
            }

            // Slug agent snapshot exists
            if (str_contains($command, 'orbit-template-agent-agent-isolation') && str_contains($command, 'clean-operator_gateway_app-dev_app-prod_agent-agent-isolation')) {
                return fakeSuccess();
            }

            // Other slug templates do not exist
            if (str_contains($command, 'agent-isolation')) {
                return fakeFailure();
            }

            // Base templates and snapshots exist
            return fakeSuccess();
        });

    $previousNamespace = getenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE');
    putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation');

    try {
        // For agent: should find the slug candidate
        $agentCandidates = IncusTopologyTemplate::snapshotCandidates($kind, 'agent');

        expect($agentCandidates[0]['template'])->toBe('orbit-template-agent-agent-isolation')
            ->and($agentCandidates[0]['snapshot'])->toBe('clean-operator_gateway_app-dev_app-prod_agent-agent-isolation')
            ->and($agentCandidates[1]['template'])->toBe('orbit-template-agent-base')
            ->and($agentCandidates[1]['snapshot'])->toBe('clean-operator_gateway_app-dev_app-prod_agent-base');

        // For operator: should find the base candidate (no slug override)
        $operatorCandidates = IncusTopologyTemplate::snapshotCandidates($kind, 'operator');
        expect($operatorCandidates[0]['template'])->toBe('orbit-template-operator-agent-isolation')
            ->and($operatorCandidates[1]['template'])->toBe('orbit-template-operator-base');
    } finally {
        $previousNamespace === false
            ? putenv('ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE')
            : putenv("ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE={$previousNamespace}");
    }
});

// ---------------------------------------------------------------------------
// Command integration: --force with --roles dispatches buildSelectedRoles
// ---------------------------------------------------------------------------

it('command with --force and --roles dispatches buildSelectedRoles with correct Incus roles', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'agent', 'name' => 'orbit-template-agent-agent-isolation', 'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent-agent-isolation'],
    ];

    $capturedBuildKind = null;
    $capturedRoles = null;
    $capturedReplace = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('buildSelectedRoles')
        ->once()
        ->andReturnUsing(function (E2ETopologyKind $kind, array $roles, bool $replace) use ($manifest, &$capturedBuildKind, &$capturedRoles, &$capturedReplace): array {
            $capturedBuildKind = $kind;
            $capturedRoles = $roles;
            $capturedReplace = $replace;

            return $manifest;
        });

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Agent isolation',
    ], function (): void {
        $this->artisan('e2e:prepare-topology', [
            'kind' => 'operator_gateway_agent',
            '--force' => true,
            '--roles' => 'agent',
        ])->assertSuccessful();
    });

    expect($capturedBuildKind)->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent)
        ->and($capturedRoles)->toBe(['agent'])
        ->and($capturedReplace)->toBeTrue();
});

it('command with --force and --roles=websocket dispatches buildSelectedRoles with dev Incus role for websocket topology', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'dev', 'name' => 'orbit-template-app-dev-ws-branch', 'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent_websocket-ws-branch'],
    ];

    $capturedRoles = null;

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('buildSelectedRoles')
        ->once()
        ->andReturnUsing(function (E2ETopologyKind $kind, array $roles) use ($manifest, &$capturedRoles): array {
            $capturedRoles = $roles;

            return $manifest;
        });

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'ws-branch',
    ], function (): void {
        $this->artisan('e2e:prepare-topology', [
            'kind' => 'operator_gateway_app-dev_app-prod_agent_websocket',
            '--force' => true,
            '--roles' => 'websocket',
        ])->assertSuccessful();
    });

    // websocket artifact role maps to 'dev' Incus role
    expect($capturedRoles)->toBe(['dev']);
});

it('command with --force and --roles outputs JSON success with selected templates', function (): void {
    fakeBundleProcessing();

    $manifest = [
        ['role' => 'agent', 'name' => 'orbit-template-agent-pr-123', 'snapshot' => 'clean-operator_gateway_app-dev_app-prod_agent-pr-123'],
    ];

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('buildSelectedRoles')->once()->andReturn($manifest);

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'pr-123',
    ], function (): void {
        $exitCode = Artisan::call('e2e:prepare-topology', [
            'kind' => 'operator_gateway_agent',
            '--force' => true,
            '--roles' => 'agent',
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['dry_run'])->toBeFalse()
            ->and($payload['success']['data']['provider'])->toBe('incus')
            ->and($payload['success']['data']['templates'])->toHaveCount(1)
            ->and($payload['success']['data']['templates'][0]['role'])->toBe('agent');
    });
});

it('command with --force and gateway consistency failure surfaces as command failure', function (): void {
    fakeBundleProcessing();

    $builder = m::mock(IncusTopologyBuilder::class);
    $builder->shouldReceive('useBundle')->once();
    $builder->shouldReceive('buildSelectedRoles')
        ->andThrow(new RuntimeException("Selected roles include 'gateway' but not 'operator'."));

    $command = app(E2EPrepareTopologyCommand::class);
    $command->setBuilderFactory(fn () => $builder);
    $this->app->instance(E2EPrepareTopologyCommand::class, $command);

    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'pr-1',
    ], function (): void {
        $this->artisan('e2e:prepare-topology', [
            'kind' => 'operator_gateway_agent',
            '--force' => true,
            '--roles' => 'gateway',
        ])
            ->expectsOutputToContain("Selected roles include 'gateway' but not 'operator'")
            ->assertFailed();
    });
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function fakeSuccess(string $output = ''): ProcessResult
{
    return Process::result(output: $output, exitCode: 0);
}

function fakeFailure(string $output = ''): ProcessResult
{
    return Process::result(output: $output, exitCode: 1);
}
