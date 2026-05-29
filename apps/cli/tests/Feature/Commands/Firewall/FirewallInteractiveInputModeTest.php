<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('firewall interactive input mode', function (): void {
    beforeEach(function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-firewall-interactive-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);
    });

    afterEach(function (): void {
        @unlink(base_path('tests/.tmp-firewall-interactive-config.json'));
    });

    it('prompts for allow rule name, node, and port before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'local-vite',
                'node' => 'app-1',
                'action' => 'allow',
            ],
        ]));

        $this->artisan('firewall:allow')
            ->expectsQuestion('Rule name', 'local-vite')
            ->expectsQuestion('Target node', 'app-1')
            ->expectsQuestion('Port', '5173')
            ->expectsOutputToContain('rule')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/firewall-rules'
            && $request->data() === [
                'action' => 'allow',
                'name' => 'local-vite',
                'node' => 'app-1',
                'direction' => 'incoming',
                'source' => 'any',
                'port' => '5173',
                'protocol' => 'tcp',
            ]);
    });

    it('prompts for deny rule name, node, and port before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'block-admin',
                'node' => 'app-1',
                'action' => 'deny',
            ],
        ]));

        $this->artisan('firewall:deny')
            ->expectsQuestion('Rule name', 'block-admin')
            ->expectsQuestion('Target node', 'app-1')
            ->expectsQuestion('Port', '9000')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->data()['action'] === 'deny'
            && $request->data()['name'] === 'block-admin'
            && $request->data()['node'] === 'app-1'
            && $request->data()['port'] === '9000');
    });

    it('prompts for destructive confirmation before removing a firewall rule', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'local-vite',
                'node' => 'app-1',
                'status' => 'removed_with_drift',
            ],
        ]));

        $this->artisan('firewall:remove', [
            'name' => 'local-vite',
            '--node' => 'app-1',
        ])
            ->expectsConfirmation("Remove firewall rule 'local-vite' from app-1?", 'yes')
            ->expectsOutputToContain('rule')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/firewall-rules/local-vite'
            && $request->data() === [
                'node' => 'app-1',
                'destructive_consent' => true,
            ]);
    });
});
