<?php

declare(strict_types=1);

use App\Librarian\TransitionalSshConsumerClassifier;
use App\Librarian\TransitionalSshConsumerFinder;
use App\Librarian\TransitionalSshInventoryBuilder;
use Illuminate\Support\Facades\Artisan;

it('keeps SSH limited to the provisioning and bootstrap lane', function (): void {
    $inventory = app(TransitionalSshInventoryBuilder::class)->build();

    expect($inventory['schema_version'])
        ->toBe(2)
        ->and($inventory['unmarked_consumers'])
        ->toBeEmpty()
        ->and($inventory['generated_from']['source_roots'])
        ->toBe([
            'apps/gateway/app',
            'apps/cli/app',
        ])
        ->and(array_map(
            static fn (array $edge): string => "{$edge['path']}#{$edge['edge']}",
            $inventory['provisioning_ssh'],
        ))
        ->toBe([
            'apps/gateway/app/Services/Operations/ProvisioningAgentInstaller.php#remote-shell.run',
            'apps/gateway/app/Services/OrbitHostInstaller.php#ssh-builder.scp-to-node',
            'apps/gateway/app/Services/OrbitHostInstaller.php#ssh-builder.scp-to',
            'apps/gateway/app/Services/OrbitHostInstaller.php#ssh-builder.enforce-for-node',
            'apps/gateway/app/Services/OrbitHostInstaller.php#ssh-builder.ssh',
            'apps/gateway/app/Services/RemoteShell/RemoteHostExecutor.php#ssh-builder.enforce-for-node',
            'apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php#remote-host-executor.run',
            'apps/gateway/app/Services/RemoteShell/SshRemoteShell.php#remote-host-executor.run',
            'apps/gateway/app/Services/RemoteShell/SshRemoteShell.php#remote-host-executor.start',
            'apps/gateway/app/Services/Security/SecurityInstallerTransport.php#remote-shell.run',
        ])
        ->and($inventory['transitional_ssh'])
        ->toBeEmpty();

    foreach ($inventory['provisioning_ssh'] as $edge) {
        expect($edge['marker_line'])->toBe($edge['call_line'] - 1);
    }
});

it('keeps the committed transitional SSH inventory fresh', function (): void {
    $builder = app(TransitionalSshInventoryBuilder::class);

    expect($builder->artifactPath())
        ->toBeFile()
        ->and(file_get_contents($builder->artifactPath()))
        ->toBe($builder->toJson($builder->build()));
});

it('reports unmarked consumers and rejects conflicting lane markers', function (): void {
    $classifier = app(TransitionalSshConsumerClassifier::class);

    expect(
        $classifier->classify(consumers: [
            'apps/gateway/app/Example.php' => <<<'PHP'
                <?php
                app(RemoteShell::class)->run($node, 'hostname');
                PHP,
        ])['unmarked_consumers'],
    )->toBe([[
        'path' => 'apps/gateway/app/Example.php',
        'call_line' => 2,
        'edge' => 'remote-shell.run',
    ]]);

    expect(fn (): array => $classifier->classify(consumers: [
        'apps/gateway/app/Conflicting.php' => <<<'PHP'
            <?php
            // @orbit-ssh-lane transitional-ssh
            // @orbit-ssh-lane provisioning-ssh
            app(RemoteShell::class)->run($node, 'hostname');
            PHP,
    ]))
        ->toThrow(
            RuntimeException::class,
            'SSH consumer apps/gateway/app/Conflicting.php declares both execution-lane markers.',
        );
});

it('classifies each SSH call edge independently', function (): void {
    $classification = app(TransitionalSshConsumerClassifier::class)->classify(consumers: [
        'apps/gateway/app/Example.php' => <<<'PHP'
            <?php
            use App\Contracts\RemoteShell;
            function apply(Node $node, RemoteShell $provisioningShell): void
            {
                // @orbit-ssh-lane provisioning-ssh
                $provisioningShell->run($node, 'first');
                $provisioningShell->run($node, 'second');
            }
            PHP,
    ]);

    expect($classification['provisioning_ssh'])
        ->toBe([[
            'path' => 'apps/gateway/app/Example.php',
            'call_line' => 6,
            'marker_line' => 5,
            'edge' => 'remote-shell.run',
        ]])
        ->and($classification['unmarked_consumers'])
        ->toBe([[
            'path' => 'apps/gateway/app/Example.php',
            'call_line' => 7,
            'edge' => 'remote-shell.run',
        ]]);
});

it('classifies multiline SSH call edges from the invocation line', function (): void {
    $classification = app(TransitionalSshConsumerClassifier::class)->classify(consumers: [
        'apps/gateway/app/DirectExample.php' => <<<'PHP'
            <?php
            // @orbit-ssh-lane provisioning-ssh
            app(
                RemoteShell::class,
            )->run($node, 'hostname');
            PHP,
        'apps/gateway/app/TypedExample.php' => <<<'PHP'
            <?php
            use App\Services\RemoteShell\RemoteExecutor;
            final class TypedExample
            {
                public function __construct(private RemoteExecutor $transport) {}
                public function apply(Node $node): void
                {
                    // @orbit-ssh-lane provisioning-ssh
                    $this->transport
                        ->run($node, 'hostname');
                }
            }
            PHP,
    ]);

    expect($classification['provisioning_ssh'])
        ->toBe([
            [
                'path' => 'apps/gateway/app/DirectExample.php',
                'call_line' => 3,
                'marker_line' => 2,
                'edge' => 'remote-shell.run',
            ],
            [
                'path' => 'apps/gateway/app/TypedExample.php',
                'call_line' => 9,
                'marker_line' => 8,
                'edge' => 'remote-executor.run',
            ],
        ])
        ->and($classification['unmarked_consumers'])
        ->toBeEmpty();
});

it('classifies aliased SSH types in receiver and container calls', function (): void {
    $classification = app(TransitionalSshConsumerClassifier::class)->classify(consumers: [
        'apps/gateway/app/Example.php' => <<<'PHP'
            <?php
            use App\Contracts\RemoteShell as ProvisioningShell;
            function apply(Node $node, ProvisioningShell $shell): void
            {
                // @orbit-ssh-lane provisioning-ssh
                $shell->run($node, 'first');
                // @orbit-ssh-lane provisioning-ssh
                app(ProvisioningShell::class)->run($node, 'second');
            }
            PHP,
    ]);

    expect($classification['provisioning_ssh'])
        ->toBe([
            [
                'path' => 'apps/gateway/app/Example.php',
                'call_line' => 6,
                'marker_line' => 5,
                'edge' => 'remote-shell.run',
            ],
            [
                'path' => 'apps/gateway/app/Example.php',
                'call_line' => 8,
                'marker_line' => 7,
                'edge' => 'remote-shell.run',
            ],
        ])
        ->and($classification['unmarked_consumers'])
        ->toBeEmpty();
});

it('classifies calls through container-resolved SSH receivers', function (): void {
    $classification = app(TransitionalSshConsumerClassifier::class)->classify(consumers: [
        'apps/gateway/app/Example.php' => <<<'PHP'
            <?php
            use App\Contracts\RemoteShell;
            $shell = app(RemoteShell::class);
            // @orbit-ssh-lane provisioning-ssh
            $shell->run($node, 'hostname');
            PHP,
    ]);

    expect($classification['provisioning_ssh'])
        ->toBe([[
            'path' => 'apps/gateway/app/Example.php',
            'call_line' => 5,
            'marker_line' => 4,
            'edge' => 'remote-shell.run',
        ]])
        ->and($classification['unmarked_consumers'])
        ->toBeEmpty();
});

it('classifies direct calls through the concrete SSH remote shell', function (): void {
    $classification = app(TransitionalSshConsumerClassifier::class)->classify(consumers: [
        'apps/gateway/app/Example.php' => <<<'PHP'
            <?php
            use App\Services\RemoteShell\SshRemoteShell;
            // @orbit-ssh-lane provisioning-ssh
            app(SshRemoteShell::class)->run($node, 'hostname');
            PHP,
    ]);

    expect($classification['provisioning_ssh'])
        ->toBe([[
            'path' => 'apps/gateway/app/Example.php',
            'call_line' => 4,
            'marker_line' => 3,
            'edge' => 'ssh-remote-shell.run',
        ]])
        ->and($classification['unmarked_consumers'])
        ->toBeEmpty();
});

it('ignores type-only SSH dependencies', function (): void {
    $classification = app(TransitionalSshConsumerClassifier::class)->classify(consumers: [
        'apps/gateway/app/Example.php' => <<<'PHP'
            <?php
            use App\Contracts\RemoteShell;

            interface Example
            {
                public function installFor(Node $node, ?RemoteShell $shell): void;
            }
            PHP,
    ]);

    expect($classification)->toBe([
        'provisioning_ssh' => [],
        'transitional_ssh' => [],
        'unmarked_consumers' => [],
    ]);
});

it('rejects an SSH lane marker that is not attached to an SSH call edge', function (): void {
    expect(fn (): array => app(TransitionalSshConsumerClassifier::class)->classify(consumers: [
        'apps/gateway/app/Example.php' => <<<'PHP'
            <?php
            // @orbit-ssh-lane provisioning-ssh
            $this->scripts->run($node, 'orbit-security', 'reconfigure', $script);
            PHP,
    ]))
        ->toThrow(
            RuntimeException::class,
            'SSH lane marker apps/gateway/app/Example.php:2 is not attached to an SSH call edge.',
        );
});

it('treats every public transport selector shape as an SSH consumer', function (string $selector): void {
    $finder = app(TransitionalSshConsumerFinder::class);

    expect($finder->isConsumer('apps/gateway/app/Example.php', $selector))
        ->toBeTrue();
})->with([
    'preference enum' => 'NodeTransportPreference',
    'forwarding helper' => 'withNodeTransportPreference',
    'HTTP header' => 'X-Orbit-Node-Transport-Preference',
    'PHP server header' => 'HTTP_X_ORBIT_NODE_TRANSPORT_PREFERENCE',
]);

it('treats either CLI selector spelling as an SSH consumer', function (string $selector): void {
    $finder = app(TransitionalSshConsumerFinder::class);

    expect($finder->isConsumer('apps/cli/app/Commands/ExampleCommand.php', $selector))
        ->toBeTrue();
})->with([
    'long option' => '--node-transport',
    'source spelling' => 'node-transport',
]);

it('passes the transitional SSH inventory freshness command', function (): void {
    $exitCode = Artisan::call('orbit:transitional-ssh-inventory', ['--check' => true]);

    expect($exitCode)->toBe(0)->and(Artisan::output())->toContain('Orbit transitional SSH inventory is up to date.');
});

it('does not advertise the removed node transport selector in active docs or Orbit skill references', function (): void {
    foreach (active_agent_transport_reference_files() as $path => $contents) {
        foreach ([
            '--node-transport',
            'transitional-ssh-fallback',
            'exact-marked transitional cleanup seam',
            'tracked SSH seam',
        ] as $retiredContract) {
            expect($contents)->not->toContain($retiredContract);
        }

        if (str_starts_with($path, '.agents/skills/orbit/references/')) {
            expect($contents)->not->toContain('transitional-ssh');
        }
    }
});

it('advertises catalog service identifiers and a Valkey example in the active Orbit process reference', function (): void {
    $repositoryRoot = realpath(base_path('../..'));

    if (! is_string($repositoryRoot)) {
        throw new RuntimeException('Unable to resolve the repository root.');
    }

    $reference = file_get_contents("{$repositoryRoot}/.agents/skills/orbit/references/process.md");

    expect($reference)
        ->toBeString()
        ->toContain('--service=<service>')
        ->toContain('Supported managed service identifier from the gateway catalog.')
        ->toContain('--service=valkey')
        ->not->toContain('--service=<mysql|redis>')
        ->not->toContain('Managed service identifier (`mysql`, `redis`, ...).');
});

it('does not publish retired SSH-era machine contracts in active docs', function (): void {
    foreach (active_agent_transport_reference_files() as $contents) {
        foreach ([
            'app.ssh_failure',
            'node.app_ssh_unreachable',
            'node_transport_required',
        ] as $retiredContract) {
            expect($contents)->not->toContain($retiredContract);
        }
    }
});

it('does not retain pre-migration RemoteShell caller audits in active docs', function (): void {
    foreach (active_agent_transport_reference_files() as $contents) {
        foreach ([
            '# RemoteShell Env Caller Audit',
            '`SshRemoteShell::run` | 13',
        ] as $staleAuditContract) {
            expect($contents)->not->toContain($staleAuditContract);
        }
    }
});

/** @return array<string, string> */
function active_agent_transport_reference_files(): array
{
    $repositoryRoot = realpath(base_path('../..'));

    if (! is_string($repositoryRoot)) {
        throw new RuntimeException('Unable to resolve the repository root.');
    }

    $files = [];

    foreach ([
        'apps/docs/content',
        '.agents/skills/orbit/references',
    ] as $relativePath) {
        $path = "{$repositoryRoot}/{$relativePath}";

        if (is_file($path)) {
            $contents = file_get_contents($path);

            if (is_string($contents)) {
                $files[$relativePath] = $contents;
            }

            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                continue;
            }

            $relativeFile = ltrim(
                string: str_replace(search: $repositoryRoot, replace: '', subject: $file->getPathname()),
                characters: '/',
            );
            $files[$relativeFile] = $contents;
        }
    }

    ksort($files);

    return $files;
}
