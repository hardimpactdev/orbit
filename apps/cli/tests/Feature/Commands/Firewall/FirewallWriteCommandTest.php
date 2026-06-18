<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('firewall write commands', function (): void {
    it('posts firewall:allow payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'local-vite',
                'node' => 'app-1',
                'action' => 'allow',
                'status' => 'expected',
            ],
        ], [
            'backend_enacted' => true,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:allow', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--port' => '5173',
            '--direction' => 'incoming',
            '--from' => '10.6.0.0/24',
            '--to' => '10.6.0.20',
            '--protocol' => 'tcp',
            '--reason' => 'local development server',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/firewall-rules'
            && $request->data() === [
                'action' => 'allow',
                'name' => 'local-vite',
                'node' => 'app-1',
                'direction' => 'incoming',
                'source' => '10.6.0.0/24',
                'destination' => '10.6.0.20',
                'port' => '5173',
                'protocol' => 'tcp',
                'reason' => 'local development server',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['rule']['action'])->toBe('allow')
            ->and($decoded['success']['meta']['backend_enacted'])->toBeTrue();
    });

    it('uses the local default node for firewall:deny when --node is omitted', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-firewall-deny-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'block-admin',
                'node' => 'default-app',
                'action' => 'deny',
            ],
        ], [
            'backend_enacted' => true,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:deny', [
            'name' => 'block-admin',
            '--port' => '9000',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/firewall-rules'
            && $request->data() === [
                'action' => 'deny',
                'name' => 'block-admin',
                'node' => 'default-app',
                'direction' => 'incoming',
                'source' => 'any',
                'port' => '9000',
                'protocol' => 'tcp',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['rule']['node'])->toBe('default-app');

        @unlink($store->path());
    });

    it('validates required firewall:allow input before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$missingNameExitCode, $missingNameOutput] = runCommand($this, 'firewall:allow', [
            '--node' => 'app-1',
            '--port' => '5173',
            '--json' => true,
        ]);

        [$missingPortExitCode, $missingPortOutput] = runCommand($this, 'firewall:allow', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $missingName = json_decode($missingNameOutput, associative: true, flags: JSON_THROW_ON_ERROR);
        $missingPort = json_decode($missingPortOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($missingNameExitCode)->toBe(1)
            ->and($missingName['error']['code'])->toBe('validation_failed')
            ->and($missingName['error']['meta']['field'])->toBe('name')
            ->and($missingPortExitCode)->toBe(1)
            ->and($missingPort['error']['code'])->toBe('validation_failed')
            ->and($missingPort['error']['meta']['field'])->toBe('port');
    });

    it('requires a firewall node target before contacting the gateway', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-firewall-empty-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'firewall:allow', [
            'name' => 'local-vite',
            '--port' => '5173',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('node_target_required')
            ->and($decoded['error']['meta']['field'])->toBe('node');

        @unlink($store->path());
    });

    it('requires force before removing a firewall rule non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'firewall:remove', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('destructive_consent_required')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('deletes firewall:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'local-vite',
                'node' => 'app-1',
                'status' => 'removed_with_drift',
            ],
        ], [
            'backend_removed' => true,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:remove', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/firewall-rules/local-vite'
            && $request->data() === [
                'node' => 'app-1',
                'destructive_consent' => true,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['rule']['status'])->toBe('removed_with_drift');
    });

    it('preserves gateway error envelopes for firewall writes', function (): void {
        fakeGateway(fakeErrorEnvelope('firewall_rule.baseline_conflict', 'Baseline policy cannot be mutated.', [
            'name' => 'ssh',
            'node' => 'app-1',
        ]), 409);

        [$exitCode, $output] = runCommand($this, 'firewall:allow', [
            'name' => 'ssh',
            '--node' => 'app-1',
            '--port' => '22',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('firewall_rule.baseline_conflict')
            ->and($decoded['error']['meta']['name'])->toBe('ssh');
    });

    it('renders firewall:allow human output as a progress tree with rule prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'local-vite',
                'node' => 'app-1',
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => '10.6.0.0/24',
                'destination' => null,
                'port' => '5173',
                'protocol' => 'tcp',
                'status' => 'enacted',
            ],
        ], [
            'backend_enacted' => true,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:allow', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--port' => '5173',
            '--from' => '10.6.0.0/24',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Allowing Firewall Rule')
            ->and($output)->toContain('Apply and verify firewall rule')
            ->and($output)->toContain('local-vite')
            ->and($output)->toContain('app-1')
            ->and($output)->toContain('allow')
            ->and($output)->toContain('5173')
            ->and($output)->not->toContain('rule:')
            ->and($output)->not->toContain('{');
    });

    it('renders firewall:deny human output as a progress tree with rule prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'block-admin',
                'node' => 'app-1',
                'direction' => 'incoming',
                'action' => 'deny',
                'source' => 'any',
                'port' => '9000',
                'protocol' => 'tcp',
                'status' => 'enacted',
            ],
        ], [
            'backend_enacted' => true,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:deny', [
            'name' => 'block-admin',
            '--node' => 'app-1',
            '--port' => '9000',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Denying Firewall Rule')
            ->and($output)->toContain('Apply and verify firewall rule')
            ->and($output)->toContain('block-admin')
            ->and($output)->toContain('deny')
            ->and($output)->not->toContain('rule:')
            ->and($output)->not->toContain('{');
    });

    it('renders firewall:allow backend apply failure recovery output in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'firewall_rule.enactment_failed',
            'The firewall rule was saved, but the backend apply failed.',
            ['next_command' => 'doctor --family=firewall_rule --restore --node=app-1'],
        ), 502);

        [$exitCode, $output] = runCommand($this, 'firewall:allow', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--port' => '5173',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('The firewall rule was saved, but the backend apply failed.')
            ->and($output)->not->toContain('"error"');
    });

    it('renders firewall:remove human output as a progress tree with the removed footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'local-vite',
                'node' => 'app-1',
                'status' => 'removed',
            ],
        ], [
            'backend_removed' => true,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:remove', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Removing Firewall Rule')
            ->and($output)->toContain('Apply and verify firewall removal')
            ->and($output)->toContain('local-vite')
            ->and($output)->toContain('app-1')
            ->and($output)->not->toContain('rule:')
            ->and($output)->not->toContain('{');
    });

    it('renders firewall:remove idempotent absence prose in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rule' => [
                'name' => 'local-vite',
                'node' => 'app-1',
                'status' => 'already_absent',
            ],
        ], [
            'backend_removed' => false,
            'warnings' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'firewall:remove', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Removing Firewall Rule')
            ->and($output)->toContain('already absent')
            ->and($output)->not->toContain('{');
    });

    it('renders firewall:remove cleanup failure recovery output in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'firewall_rule.cleanup_failed',
            'The backend firewall rule could not be removed, so gateway configuration was kept.',
            ['next_command' => 'doctor --family=firewall_rule --restore --node=app-1'],
        ), 502);

        [$exitCode, $output] = runCommand($this, 'firewall:remove', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('The backend firewall rule could not be removed, so gateway configuration was kept.')
            ->and($output)->not->toContain('"error"');
    });
});
