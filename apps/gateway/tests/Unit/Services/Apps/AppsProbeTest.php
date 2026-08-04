<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Apps;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\DriftKind;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\AppsProbe;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new AppsProbe;
});

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('app');
        expect($this->probe->label())->toBe('Apps');
    });

    it('returns empty snapshot from introspect', function (): void {
        $app = new Project(['name' => 'site']);
        $snapshot = $this->probe->introspect($app);

        expect($snapshot->isEmpty())->toBeTrue();
    });
});

describe('docker-first probe', function (): void {
    it('dispatches app introspection through the internal agent-push command', function (): void {
        $node = appsProbeAgentNode();
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
                'document_root' => 'public',
            ]);
        fake_apps_introspect_probe(apps_introspect_snapshot('docs'));

        $snapshot = new AppsProbe()->introspect($app);

        expect($snapshot->get('docs'))->toMatchArray([
            'path_exists' => true,
            'root_exists' => true,
            'root_inside_path' => true,
            'docker_available' => true,
            'container_exists' => true,
            'container_spec_matches' => true,
            'container_running' => true,
            'system_user_exists' => true,
            'fs_permissions_ok' => true,
        ]);
        expect(apps_introspect_probe_was_sent())->toBeTrue();
    });

    it('passes app reality inputs as a typed JSON payload', function (): void {
        $node = appsProbeAgentNode();
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
                'document_root' => 'web',
                'php_version' => '8.5',
            ]);
        fake_apps_introspect_probe(apps_introspect_snapshot('docs'));

        new AppsProbe()->introspect($app);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

            return (
                $request['argv'][0] === 'internal:app-introspect:probe'
                && $payload['document_root'] === 'web'
                && $payload['runtime_container_name'] === 'orbit-app-docs'
            );
        });
    });

    it('passes app instance runtime inputs as a concrete target payload', function (): void {
        $beast = appsProbeAgentNode(['name' => 'beast', 'wireguard_address' => '10.6.0.62']);
        $nmbp = appsProbeAgentNode(['name' => 'nmbp', 'platform' => 'darwin', 'user' => 'nckrtl', 'tld' => 'nmbp']);
        $app = Project::factory()
            ->for($beast, 'node')
            ->create([
                'name' => 'hauser',
                'path' => '/home/nckrtl/apps/hauser',
                'document_root' => 'public',
                'php_version' => '8.5',
            ]);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $nmbp->id,
                node: 'nmbp',
                path: '/Users/nckrtl/apps/hauser',
                document_root: 'public',
                domain: 'hauser.nmbp',
            ),
        ]);
        fake_apps_introspect_probe(apps_introspect_snapshot('hauser.nmbp'));

        new AppsProbe()->introspectInstance($app, $instance);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

            return (
                $request->url() === 'http://10.6.0.63:9477/v1/commands'
                && $request['argv'][0] === 'internal:app-introspect:probe'
                && $payload['name'] === 'hauser.nmbp'
                && $payload['path'] === '/Users/nckrtl/apps/hauser'
                && $payload['runtime_container_name'] === 'orbit-app-hauser-nmbp'
                && $payload['runtime_config_path'] === '/Users/nckrtl/.config/orbit/apps/hauser-nmbp.ini'
            );
        });
    });
});

describe('source path and document root reality', function (): void {
    it('introspects source path, document root, and runtime container reality on the owning app node', function (): void {
        $node = appsProbeAgentNode();
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
                'document_root' => 'public',
            ]);
        fake_apps_introspect_probe(apps_introspect_snapshot('docs'));

        $snapshot = new AppsProbe()->introspect($app);

        expect($snapshot->get('docs'))->toMatchArray([
            'path_exists' => true,
            'root_exists' => true,
            'root_inside_path' => true,
            'docker_available' => true,
            'container_exists' => true,
            'container_spec_matches' => true,
            'container_running' => true,
            'system_user_exists' => true,
            'fs_permissions_ok' => true,
        ]);
        expect(apps_introspect_probe_was_sent())->toBeTrue();
    });

    it('detects missing source paths', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => [
                'path_exists' => false,
                'root_exists' => false,
                'root_inside_path' => true,
            ],
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.path_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'app.root_missing'))->toBeNull();
    });

    it('hands app instance runtime mismatches to process doctor without duplicate app issues', function (): void {
        $node = appNode(['name' => 'nmbp', 'platform' => 'darwin', 'user' => 'nckrtl', 'tld' => 'nmbp']);
        $app = Project::factory()->create([
            'name' => 'hauser',
            'node_id' => $node->id,
            'path' => '/Users/nckrtl/apps/hauser',
            'document_root' => 'public',
        ]);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: 'nmbp',
                path: '/Users/nckrtl/apps/hauser',
                document_root: 'public',
                domain: 'hauser.nmbp',
            ),
        ]);

        $drift = $this->probe->diffInstance($app, $instance, new ProbeSnapshot([
            'hauser.nmbp' => convergedRuntimeSnapshot(['container_spec_matches' => false]),
        ]));

        expect(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('detects missing document roots after the source path exists', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => [
                'path_exists' => true,
                'root_exists' => false,
                'root_inside_path' => true,
            ],
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.root_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects document roots that resolve outside the app path', function (): void {
        $node = appNode();
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'path' => '/home/orbit/apps/docs',
                'document_root' => '../shared/public',
            ]);

        $drift = new AppsProbe()->diff($app, new ProbeSnapshot([]));

        expect(issue($drift, 'app.root_outside_path')?->kind)->toBe(DriftKind::Divergent);
    });
});

describe('PHP runtime reality', function (): void {
    it('detects unavailable Docker runtimes on the owning app node', function (): void {
        $node = appNode();
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'php_version' => '8.5',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot(['docker_available' => false]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.php_version_unavailable')?->kind)->toBe(DriftKind::Missing);
    });

    it('does not report PHP runtime drift when the source path is missing', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'path_exists' => false,
                'root_exists' => false,
                'docker_available' => false,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.php_version_unavailable'))->toBeNull();
    });

    it('emits app.php_version_unavailable when the selected FrankenPHP image is not on the owning node', function (): void {
        $node = appNode();
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'php_version' => '8.5',
                'path' => '/home/orbit/apps/docs',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot(['runtime_image_available' => false]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);
        $entry = issue($drift, 'app.php_version_unavailable');

        expect($entry?->kind)
            ->toBe(DriftKind::Missing)
            ->and($entry?->detail['php_version'] ?? null)
            ->toBe('8.5')
            ->and($entry?->detail['expected_image'] ?? null)
            ->toBe('ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm')
            ->and(issue($drift, 'app.runtime_container_missing'))
            ->toBeNull()
            ->and(issue($drift, 'app.runtime_container_mismatch'))
            ->toBeNull();
    });

    it(
        'hands unknown image-probe failure with no runtime unit to process doctor',
        function (): void {
            $node = appNode();
            $app = Project::factory()
                ->for($node, 'node')
                ->create([
                    'name' => 'docs',
                    'php_version' => '8.5',
                    'path' => '/home/orbit/apps/docs',
                ]);

            // Image probe failed for unknown reason AND no container observed:
            // surface as the documented `app.runtime_container_missing` so doctor
            // restore re-attempts apply (which throws via the manager preflight
            // if the underlying issue persists). Must NOT emit
            // `app.php_version_unavailable` and must NOT introduce an undocumented
            // `app.runtime_image_probe_failed` key.
            $snapshot = new ProbeSnapshot([
                'docs' => convergedRuntimeSnapshot([
                    'runtime_image_available' => false,
                    'runtime_image_probe_failed' => true,
                    'container_exists' => false,
                    'container_spec_matches' => false,
                    'container_running' => false,
                ]),
            ]);

            $drift = new AppsProbe()->diff($app, $snapshot);
            expect(issue($drift, 'app.runtime_container_missing'))
                ->toBeNull()
                ->and(issue($drift, 'app.php_version_unavailable'))
                ->toBeNull()
                ->and(issue($drift, 'app.runtime_image_probe_failed'))
                ->toBeNull()
                ->and(issue($drift, 'app.runtime_container_mismatch'))
                ->toBeNull();
        },
    );

    it('hands unknown image-probe failure with a mismatched runtime unit to process doctor', function (): void {
        $node = appNode();
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'php_version' => '8.5',
                'path' => '/home/orbit/apps/docs',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'runtime_image_available' => false,
                'runtime_image_probe_failed' => true,
                'container_exists' => true,
                'container_spec_matches' => false,
                'container_running' => true,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_mismatch'))
            ->toBeNull()
            ->and(issue($drift, 'app.php_version_unavailable'))
            ->toBeNull()
            ->and(issue($drift, 'app.runtime_image_probe_failed'))
            ->toBeNull()
            ->and(issue($drift, 'app.runtime_container_missing'))
            ->toBeNull();
    });

    it('does not emit app.php_version_unavailable for static apps regardless of node image availability', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->static()->create(['name' => 'marketing']);

        $snapshot = new ProbeSnapshot([
            'marketing' => convergedRuntimeSnapshot(['runtime_image_available' => false]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.php_version_unavailable'))->toBeNull();
    });
});

describe('runtime container reality', function (): void {
    it('hands missing FrankenPHP runtime units to process doctor without duplicate app issues', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'container_exists' => false,
                'container_spec_matches' => false,
                'container_running' => false,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_missing'))->toBeNull();
        expect(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('hands FrankenPHP runtime unit mismatches to process doctor without duplicate app issues', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot(['container_spec_matches' => false]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('does not report runtime container drift before Docker is available', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'docker_available' => false,
                'container_exists' => false,
                'container_spec_matches' => false,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_missing'))->toBeNull();
        expect(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it('does not report runtime container drift for static apps', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->static()->create(['name' => 'marketing']);

        $snapshot = new ProbeSnapshot([
            'marketing' => convergedRuntimeSnapshot([
                'container_exists' => false,
                'container_spec_matches' => false,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_container_missing'))->toBeNull();
        expect(issue($drift, 'app.runtime_container_mismatch'))->toBeNull();
    });

    it(
        'hands a stopped FrankenPHP runtime unit to process doctor without duplicate app issues',
        function (): void {
            $node = appNode();
            $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

            $snapshot = new ProbeSnapshot([
                'docs' => convergedRuntimeSnapshot([
                    'container_exists' => true,
                    'container_spec_matches' => true,
                    'container_running' => false,
                ]),
            ]);

            $drift = new AppsProbe()->diff($app, $snapshot);
            expect(issue($drift, 'app.runtime_container_missing'))
                ->toBeNull()
                ->and(issue($drift, 'app.runtime_container_mismatch'))
                ->toBeNull();
        },
    );
});

describe('managed runtime config reality', function (): void {
    it('detects missing managed runtime config files for PHP apps', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'runtime_config_exists' => false,
                'runtime_config_matches' => false,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_config_missing')?->kind)->toBe(DriftKind::Missing);
        expect(issue($drift, 'app.runtime_config_mismatch'))->toBeNull();
    });

    it('detects managed runtime config hash mismatches', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot(['runtime_config_matches' => false]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_config_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('does not report runtime config drift for static apps', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->static()->create(['name' => 'marketing']);

        $snapshot = new ProbeSnapshot([
            'marketing' => convergedRuntimeSnapshot([
                'runtime_config_exists' => false,
                'runtime_config_matches' => false,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.runtime_config_missing'))->toBeNull();
        expect(issue($drift, 'app.runtime_config_mismatch'))->toBeNull();
    });

    it('lists orphan user config runtime ini files via the node config scan when the directory probe is present', function (): void {
        $node = appsProbeAgentNode();
        fakeAppsRuntimeConfigsProbe(
            "orbit-config-dir:present\n/home/orbit/.config/orbit/apps/docs.ini\n/home/orbit/.config/orbit/apps/marketing.ini\n",
        );

        $probe = new AppsProbe()->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)
            ->toBe('present')
            ->and($probe->error)
            ->toBe('')
            ->and($probe->configs->keys())
            ->toContain('docs')
            ->and($probe->configs->keys())
            ->toContain('marketing')
            ->and($probe->configs->get('docs'))
            ->toBe([
                'path' => '/home/orbit/.config/orbit/apps/docs.ini',
                'app_slug' => 'docs',
            ])
            ->and(appsRuntimeConfigsProbeWasSent())
            ->toBeTrue();
    });

    it('reports the runtime config directory as proven-absent when the local probe proves it is missing', function (): void {
        $node = appsProbeAgentNode();
        fakeAppsRuntimeConfigsProbe("orbit-config-dir:absent\n");

        $probe = new AppsProbe()->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)
            ->toBe('absent')
            ->and($probe->error)
            ->toBe('')
            ->and($probe->configs->isEmpty())
            ->toBeTrue();
    });

    it('reports unknown probe failures distinctly (does NOT silently hide stale runtime_config_extra artifacts)', function (): void {
        $node = appsProbeAgentNode();
        // Even if find emits artifact lines after an error sentinel, we must
        // not surface them — they are not trustworthy. The status carries
        // forward as Error and the configs snapshot is intentionally empty.
        fakeAppsRuntimeConfigsProbe(
            "orbit-config-dir:error sudo: a terminal is required to read the password\n/home/orbit/.config/orbit/apps/stale.ini\n",
        );

        $probe = new AppsProbe()->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)
            ->toBe('error')
            ->and($probe->error)
            ->toContain('terminal')
            ->and($probe->configs->isEmpty())
            ->toBeTrue();
    });

    it(
        'reports as error (not clean empty) when the config scan itself fails (would otherwise hide stale runtime_config_extra)',
        function (): void {
            $node = appsProbeAgentNode();
            // Probe script must distinguish a successful directory check followed
            // by a FAILING find from a successful directory check with no entries.
            // The internal agent probe emits `orbit-config-dir:error <stderr>` so the orchestrator
            // does not treat this as a clean empty snapshot.
            fakeAppsRuntimeConfigsProbe(
                "orbit-config-dir:error find: '/home/orbit/.config/orbit/apps': Permission denied\n",
            );

            $probe = new AppsProbe()->introspectNodeRuntimeConfigs($node);

            expect($probe->status->value)
                ->toBe('error')
                ->and($probe->error)
                ->toContain('Permission denied')
                ->and($probe->configs->isEmpty())
                ->toBeTrue();
        },
    );

    it('captures both find stdout and find exit status so a non-zero find exit becomes error even without stderr text', function (): void {
        $node = appsProbeAgentNode();
        // Simulate: internal agent probe emits error sentinel because find exited non-zero
        // with no stderr text (the script falls back to a synthesized message).
        fakeAppsRuntimeConfigsProbe("orbit-config-dir:error runtime config scan failed\n");

        $probe = new AppsProbe()->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)
            ->toBe('error')
            ->and($probe->error)
            ->toContain('runtime config scan failed')
            ->and($probe->configs->isEmpty())
            ->toBeTrue();
    });

    it(
        'reports Error status (no clean absence) when the remote shell call itself throws — SSH/transport failure must not abort doctor',
        function (): void {
            $node = appsProbeAgentNode();
            app()->instance(
                RunsInternalCommands::class,
                app(RemoteLocalExecutor::class),
            );
            Http::preventStrayRequests();
            Http::fake([
                'http://10.6.0.63:9477/v1/commands' => Http::response(['error' => 'agent unavailable'], 503),
            ]);

            $probe = new AppsProbe()->introspectNodeRuntimeConfigs($node);

            expect($probe->status->value)
                ->toBe('error')
                ->and($probe->error)
                ->toContain('HTTP 503')
                ->and($probe->configs->isEmpty())
                ->toBeTrue();
        },
    );

    it('reports Error status (no clean absence) when the agent response has no sentinel', function (): void {
        $node = appsProbeAgentNode();
        fakeAppsRuntimeConfigsProbe('agent command failed before sentinel');

        $probe = new AppsProbe()->introspectNodeRuntimeConfigs($node);

        expect($probe->status->value)
            ->toBe('error')
            ->and($probe->error)
            ->toContain('no status sentinel')
            ->and($probe->configs->isEmpty())
            ->toBeTrue();
    });

    it(
        'dispatches runtime config scans through the agent internal command',
        function (): void {
            $node = appsProbeAgentNode();
            fakeAppsRuntimeConfigsProbe("orbit-config-dir:absent\n");

            new AppsProbe()->introspectNodeRuntimeConfigs($node);

            expect(appsRuntimeConfigsProbeWasSent())->toBeTrue();
        },
    );
});

describe('production security reality', function (): void {
    it('detects production app runtime container isolation drift', function (): void {
        $node = appNode([], role: 'app-prod');
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'environment' => 'production',
                'path' => '/home/orbit/apps/docs',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'container_exists' => false,
                'container_spec_matches' => false,
                'system_user_exists' => false,
                'fs_permissions_ok' => false,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(issue($drift, 'app.security.system_user')?->kind)
            ->toBe(DriftKind::Missing)
            ->and(issue($drift, 'app.security.fs_permissions')?->kind)
            ->toBe(DriftKind::Divergent)
            ->and(issue($drift, 'app.security.runtime_container_isolation')?->kind)
            ->toBe(DriftKind::Missing);
    });

    it('does not apply production app security drift to development apps', function (): void {
        $node = appNode();
        $app = Project::factory()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
            ]);

        $snapshot = new ProbeSnapshot([
            'docs' => convergedRuntimeSnapshot([
                'system_user_exists' => false,
                'fs_permissions_ok' => false,
                'container_exists' => false,
                'container_spec_matches' => false,
            ]),
        ]);

        $drift = new AppsProbe()->diff($app, $snapshot);

        expect(
            collect($drift)
                ->pluck('key')
                ->filter(fn (string $key): bool => str_starts_with($key, 'app.security.'))
                ->all(),
        )
            ->toBe([]);
    });
});

describe('registry intent', function (): void {
    it('passes complete app records on active app nodes', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete app records', function (): void {
        $node = appNode();

        $id = DB::table('apps')->insertGetId([
            'name' => 'incomplete',
            'node_id' => $node->id,
            'environment' => '',
            'path' => '',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $app = Project::findOrFail($id);

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->family)->toBe('app');
        expect($drift[0]->key)->toBe('app.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });
});

describe('owning node eligibility', function (): void {
    it('requires an active app node owner', function (callable $createNode): void {
        $node = $createNode();
        $app = Project::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $ownerIssues = array_values(array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.owner_node_invalid',
        ));

        expect($ownerIssues)->toHaveCount(1);
        expect($ownerIssues[0]->kind)->toBe(DriftKind::Divergent);
    })->with([
        'gateway owner' => [fn (): Node => Node::factory()->gateway()->create(['status' => 'active'])],
        'inactive app owner' => [fn (): Node => Node::factory()->appDev()->create(['status' => 'inactive'])],
    ]);

    it('accepts active app node owners', function (): void {
        $node = appNode();
        $app = Project::factory()->for($node, 'node')->create();

        $drift = $this->probe->diff($app, new ProbeSnapshot([]));
        $ownerIssues = array_filter(
            $drift,
            fn (DriftEntry $entry): bool => $entry->key === 'app.owner_node_invalid',
        );

        expect($ownerIssues)->toHaveCount(0);
    });
});

function issue(array $drift, string $key): ?DriftEntry
{
    return collect($drift)->first(fn (DriftEntry $entry): bool => $entry->key === $key);
}

function convergedRuntimeSnapshot(array $overrides = []): array
{
    return [
        'path_exists' => true,
        'root_exists' => true,
        'root_inside_path' => true,
        'docker_available' => true,
        'container_exists' => true,
        'container_spec_matches' => true,
        'container_running' => true,
        'system_user_exists' => true,
        'fs_permissions_ok' => true,
        'runtime_config_exists' => true,
        'runtime_config_matches' => true,
        'runtime_image_available' => true,
        'runtime_image_probe_failed' => false,
        ...$overrides,
    ];
}

function appNode(array $overrides = [], string $role = 'app-dev'): Node
{
    return createTestAppHostNode(
        [
            ...$overrides,
        ],
        role: $role,
    );
}

function appsProbeAgentNode(array $overrides = []): Node
{
    return appNode([
        'wireguard_address' => '10.6.0.63',
        'managed' => true,
        ...$overrides,
    ]);
}

function fakeAppsRuntimeConfigsProbe(string $stdout): void
{
    app()->instance(
        RunsInternalCommands::class,
        app(RemoteLocalExecutor::class),
    );
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.63:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'app-runtime-configs.probe',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'success' => [
                            'data' => [
                                'stdout' => $stdout,
                            ],
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ]),
    ]);
}

function appsRuntimeConfigsProbeWasSent(): bool
{
    Http::assertSent(
        fn (Request $request): bool => (
            $request->url() === 'http://10.6.0.63:9477/v1/commands'
            && $request['binary'] === 'orbit'
            && $request['argv'][0] === 'internal:app-runtime-configs:probe'
        ),
    );

    return true;
}

/**
 * @param  array<string, mixed>  $snapshot
 */
function fake_apps_introspect_probe(array $snapshot): void
{
    app()->instance(
        RunsInternalCommands::class,
        app(RemoteLocalExecutor::class),
    );
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.63:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'app.introspect',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'success' => [
                            'data' => [
                                'snapshot' => $snapshot,
                            ],
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function apps_introspect_snapshot(string $name): array
{
    return [
        'name' => $name,
        'path_exists' => true,
        'root_exists' => true,
        'root_inside_path' => true,
        'docker_available' => true,
        'container_exists' => true,
        'container_spec_matches' => true,
        'container_running' => true,
        'system_user_exists' => true,
        'fs_permissions_ok' => true,
        'runtime_config_exists' => true,
        'runtime_config_matches' => true,
        'runtime_image_available' => true,
        'runtime_image_probe_failed' => false,
    ];
}

function apps_introspect_probe_was_sent(): bool
{
    Http::assertSent(
        fn (Request $request): bool => (
            $request->url() === 'http://10.6.0.63:9477/v1/commands'
            && $request['binary'] === 'orbit'
            && $request['argv'][0] === 'internal:app-introspect:probe'
        ),
    );

    return true;
}
