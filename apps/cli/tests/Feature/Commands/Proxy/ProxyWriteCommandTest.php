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

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/proxy-routes'
            && $request->data() === [
                'domain' => 'vite.docs.test',
                'node' => 'app-1',
                'upstream' => 'http://127.0.0.1:5173',
                'force' => true,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['route']['domain'])->toBe('vite.docs.test')
            ->and($decoded['success']['meta']['action'])->toBe('created');
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

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/proxy-routes'
            && $request->data() === [
                'domain' => 'old.test',
                'node' => 'default-app',
                'redirect' => 'https://docs.test',
                'code' => 302,
                'force' => false,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['route']['node'])->toBe('default-app');

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

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('node_target_required')
            ->and($decoded['error']['meta']['field'])->toBe('node');

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

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['fields'])->toBe(['upstream', 'redirect']);
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

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('proxy.domain_conflict')
            ->and($decoded['error']['meta']['owner_type'])->toBe('app');
    });

    it('requires force before removing a proxy route non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'proxy:remove', [
            'domain' => 'old.test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('destructive_consent_required')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('deletes proxy:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'old.test',
                'node' => 'app-1',
                'status' => 'removed_with_drift',
            ],
        ], [
            'backend_removed' => false,
            'tls_removed' => false,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'proxy:remove', [
            'domain' => 'old.test',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/proxy-routes/old.test'
            && $request->data() === [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['route']['status'])->toBe('removed_with_drift');
    });

    it('prompts before removing a proxy route without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'old.test',
                'node' => 'app-1',
                'status' => 'removed_with_drift',
            ],
        ], [
            'backend_removed' => false,
            'tls_removed' => false,
            'warnings' => [],
        ]));

        $this->artisan('proxy:remove', ['domain' => 'old.test'])
            ->expectsConfirmation("Remove proxy route 'old.test'?", 'yes')
            ->expectsOutputToContain('route')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/proxy-routes/old.test');
    });
});
