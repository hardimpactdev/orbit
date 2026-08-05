<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('proxy write commands', function (): void {
    it('posts proxy:add upstream payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'vite.docs.test',
                'node' => 'app-1',
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
            ],
        ], [
            'action' => 'created',
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:add', [
            'domain' => 'vite.docs.test',
            '--node' => 'app-1',
            '--upstream' => 'http://127.0.0.1:5173',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/proxy-routes'
                && $request->data() === [
                    'domain' => 'vite.docs.test',
                    'node' => 'app-1',
                    'upstream' => 'http://127.0.0.1:5173',
                    'force' => true,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['route']['domain'])
            ->toBe('vite.docs.test')
            ->and($decoded['success']['meta']['action'])
            ->toBe('created');
    });

    it('uses the local default node for proxy:add when --node is omitted', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-proxy-add-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'old.test',
                'node' => 'default-app',
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
            ],
        ], [
            'action' => 'created',
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:add', [
            'domain' => 'old.test',
            '--redirect' => 'https://docs.test',
            '--code' => '302',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/proxy-routes'
                && $request->data() === [
                    'domain' => 'old.test',
                    'node' => 'default-app',
                    'redirect' => 'https://docs.test',
                    'code' => 302,
                    'force' => false,
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['route']['node'])->toBe('default-app');

        @unlink($store->path());
    });

    it('requires a node target before contacting the gateway', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-proxy-add-empty-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'proxy:add', [
            'domain' => 'vite.docs.test',
            '--upstream' => 'http://127.0.0.1:5173',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('node_target_required')
            ->and($decoded['error']['meta']['field'])
            ->toBe('node');

        @unlink($store->path());
    });

    it('validates mutually exclusive proxy:add targets before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'proxy:add', [
            'domain' => 'vite.docs.test',
            '--node' => 'app-1',
            '--upstream' => 'http://127.0.0.1:5173',
            '--redirect' => 'https://docs.test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['fields'])
            ->toBe(['upstream', 'redirect']);
    });

    it('preserves gateway error envelopes for proxy:add', function (): void {
        fakeGateway(fakeErrorEnvelope('proxy.domain_conflict', "Domain 'docs.test' is owned by app.", [
            'domain' => 'docs.test',
            'owner_type' => 'app',
        ]), 409);

        [$exitCode, $output] = runCommand($this, 'proxy:add', [
            'domain' => 'docs.test',
            '--node' => 'app-1',
            '--upstream' => 'http://127.0.0.1:5173',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('proxy.domain_conflict')
            ->and($decoded['error']['meta']['owner_type'])
            ->toBe('app');
    });

    it('requires force before removing a proxy route non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'proxy:remove', [
            'domain' => 'old.test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('force')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('destructive_consent_required');
    });

    it('deletes proxy:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'old.test',
                'node' => 'app-1',
                'status' => 'removed',
            ],
        ], [
            'backend_removed' => true,
            'tls_removed' => true,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:remove', [
            'domain' => 'old.test',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/proxy-routes/old.test'
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['route']['status'])->toBe('removed');
    });

    it('prompts before removing a proxy route without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'old.test',
                'node' => 'app-1',
                'status' => 'removed',
            ],
        ], [
            'backend_removed' => true,
            'tls_removed' => true,
            'warnings' => [],
        ]));

        $this
            ->artisan('proxy:remove', ['domain' => 'old.test'])
            ->expectsConfirmation("Remove proxy route 'old.test'?", 'yes')
            ->expectsOutputToContain('route')
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/proxy-routes/old.test'
            ),
        );
    });

    it('renders proxy:add human output as a progress tree with route detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'vite.docs.test',
                'kind' => 'proxy',
                'owner' => ['type' => 'custom', 'name' => null],
                'node' => 'app-1',
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'redirect_code' => null,
                'tls' => ['managed_by' => 'orbit', 'trusted_by_gateway_ca' => true],
                'status' => 'enacted',
            ],
        ], [
            'action' => 'created',
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:add', [
            'domain' => 'vite.docs.test',
            '--node' => 'app-1',
            '--upstream' => 'http://127.0.0.1:5173',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Adding Proxy Route')
            ->and($output)
            ->toContain('Apply and verify TLS material')
            ->and($output)
            ->toContain("Proxy route 'vite.docs.test' added")
            ->and($output)
            ->toContain('Domain: vite.docs.test')
            ->and($output)
            ->toContain('Serving node: app-1')
            ->and($output)
            ->toContain('Target: upstream http://127.0.0.1:5173')
            ->and($output)
            ->toContain('Backend apply: enacted')
            ->and($output)
            ->not->toContain('{');
    });

    it('renders proxy:add redirect code detail for redirect routes', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'redirect.docs.test',
                'kind' => 'redirect',
                'owner' => ['type' => 'custom', 'name' => null],
                'node' => 'app-1',
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'redirect_code' => 302,
                'tls' => ['managed_by' => 'orbit', 'trusted_by_gateway_ca' => true],
                'status' => 'enacted',
            ],
        ], [
            'action' => 'created',
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:add', [
            'domain' => 'redirect.docs.test',
            '--node' => 'app-1',
            '--redirect' => 'https://docs.test',
            '--code' => '302',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Kind: redirect')
            ->and($output)
            ->toContain('Redirect code: 302');
    });

    it('renders proxy:add gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('proxy.domain_conflict', "Domain 'docs.test' is owned by app."), 409);

        [$exitCode, $output] = runCommand($this, 'proxy:add', [
            'domain' => 'docs.test',
            '--node' => 'app-1',
            '--upstream' => 'http://127.0.0.1:5173',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('is owned by app')
            ->and($output)
            ->not->toContain('"error"');
    });

    it('renders proxy:remove human output as a progress tree with cleanup detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'old.test',
                'kind' => 'redirect',
                'node' => 'app-1',
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'status' => 'removed',
            ],
        ], [
            'backend_removed' => true,
            'tls_removed' => true,
            'removal_reason' => 'custom',
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:remove', [
            'domain' => 'old.test',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Removing Proxy Route')
            ->and($output)
            ->toContain('Apply and verify proxy removal')
            ->and($output)
            ->toContain("Proxy route 'old.test' removed")
            ->and($output)
            ->toContain('Domain: old.test')
            ->and($output)
            ->toContain('Serving node: app-1')
            ->and($output)
            ->toContain('Backend cleanup: completed')
            ->and($output)
            ->toContain('TLS cleanup: completed')
            ->and($output)
            ->not->toContain('{');
    });

    it('renders orphan-owner proxy:remove safety detail in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'auth.craft-starterkit-react.test',
                'kind' => 'workspace',
                'owner' => ['type' => 'workspace', 'name' => null],
                'node' => 'app-1',
                'target' => ['type' => 'workspace', 'value' => null],
                'status' => 'removed',
            ],
        ], [
            'backend_removed' => true,
            'tls_removed' => true,
            'removal_reason' => 'orphan_owner',
            'owner_type' => 'workspace',
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:remove', [
            'domain' => 'auth.craft-starterkit-react.test',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Proxy route 'auth.craft-starterkit-react.test' removed")
            ->and($output)
            ->toContain('Domain: auth.craft-starterkit-react.test')
            ->and($output)
            ->toContain('Owner: workspace (orphaned)')
            ->and($output)
            ->toContain('Safe because: the recorded workspace owner no longer exists')
            ->and($output)
            ->not->toContain('{');
    });

    it('renders proxy:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'proxy.cleanup_failed',
            "Proxy route 'old.test' registry is intact, but backend/TLS cleanup failed: simulated",
            [
                'domain' => 'old.test',
                'node' => 'app-1',
                'backend_removed' => false,
                'tls_removed' => false,
                'next_command' => 'doctor --family=proxy --restore --node=app-1',
            ],
        ), 422);

        [$exitCode, $output] = runCommand($this, 'proxy:remove', [
            'domain' => 'old.test',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('registry is intact')
            ->and($output)
            ->toContain('backend/TLS cleanup failed')
            ->and($output)
            ->not->toContain('"error"');
    });
});
