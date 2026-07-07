<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->tempPath = tempnam(sys_get_temp_dir(), 'orbit-nd-test-').'.json';
    $this->store = new OrbitConfigStore(overridePath: $this->tempPath);
    @unlink($this->tempPath);

    app()->instance(OrbitConfigStore::class, $this->store);
});

afterEach(function (): void {
    if (isset($this->tempPath) && is_file($this->tempPath)) {
        @unlink($this->tempPath);
    }
});

describe('node:default', function (): void {
    describe('show sub-action (non-interactive, no name, no --clear)', function (): void {
        it('returns empty state when no default is set', function (): void {
            [$exitCode, $output] = runCommand($this, 'node:default', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['action'])
                ->toBe('show')
                ->and($decoded['success']['data']['default_node'])
                ->toBeNull();
        });

        it('returns stored default when one is set', function (): void {
            $this->store->setDefaultNode('app-1');

            [$exitCode, $output] = runCommand($this, 'node:default', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['action'])
                ->toBe('show')
                ->and($decoded['success']['data']['default_node']['name'])
                ->toBe('app-1')
                ->and($decoded['success']['data']['default_node']['role'])
                ->toBeNull()
                ->and($decoded['success']['data']['default_node'])
                ->not->toHaveKey('environment');
        });

        it('does not call the gateway for show', function (): void {
            $this->store->setDefaultNode('app-1');

            [$exitCode] = runCommand($this, 'node:default', ['--json' => true]);

            expect($exitCode)->toBe(0);
        });
    });

    describe('set sub-action (name provided)', function (): void {
        it('stores the node and returns set action on success', function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'nodes' => [
                    ['name' => 'app-1', 'role' => 'app-dev'],
                ],
            ]));

            [$exitCode, $output] = runCommand($this, 'node:default', ['name' => 'app-1', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['action'])
                ->toBe('set')
                ->and($decoded['success']['data']['default_node']['name'])
                ->toBe('app-1')
                ->and($decoded['success']['data']['default_node']['role'])
                ->toBe('app-dev')
                ->and($decoded['success']['data']['default_node'])
                ->not
                ->toHaveKey('environment')
                ->and($this->store->defaultNode())
                ->toBe('app-1');
        });

        it('returns node.not_found when node is not in the visible list', function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'nodes' => [
                    ['name' => 'app-2', 'role' => 'app-dev'],
                ],
            ]));

            [$exitCode, $output] = runCommand($this, 'node:default', ['name' => 'app-unknown', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($decoded['error']['code'])
                ->toBe('node.not_found')
                ->and($decoded['error']['meta']['name'])
                ->toBe('app-unknown')
                ->and($this->store->defaultNode())
                ->toBeNull();
        });

        it('stores any visible node role as the local default', function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'nodes' => [
                    ['name' => 'gateway-1', 'role' => 'gateway'],
                    ['name' => 'app-1', 'role' => 'app-dev'],
                ],
            ]));

            [$exitCode, $output] = runCommand($this, 'node:default', ['name' => 'gateway-1', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['default_node'])
                ->toMatchArray([
                    'name' => 'gateway-1',
                    'role' => 'gateway',
                ])
                ->and($this->store->defaultNode())
                ->toBe('gateway-1');
        });

        it('returns gateway_unavailable when gateway is down', function (): void {
            fakeGatewayDown('connection refused');

            [$exitCode, $output] = runCommand($this, 'node:default', ['name' => 'app-1', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
        });

        it('returns gateway_unreachable_wireguard on WireGuard timeout', function (): void {
            fakeGatewayDown('timed out');

            [$exitCode, $output] = runCommand($this, 'node:default', ['name' => 'app-1', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
        });

        it('does not mutate gateway node configuration', function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'nodes' => [
                    ['name' => 'app-1', 'role' => 'app-dev'],
                ],
            ]));

            [$exitCode] = runCommand($this, 'node:default', ['name' => 'app-1', '--json' => true]);

            expect($exitCode)->toBe(0);

            Http::assertSentCount(1);
            Http::assertSent(
                fn (Request $request): bool => (
                    $request->method() === 'GET'
                    && str_contains($request->url(), '/api/nodes')
                    && ! str_contains($request->url(), 'role=app-dev')
                ),
            );
        });

        it('stores the name locally without gateway mutation', function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'nodes' => [
                    ['name' => 'app-1', 'role' => 'app-dev'],
                ],
            ]));

            [$exitCode] = runCommand($this, 'node:default', ['name' => 'app-1', '--json' => true]);

            expect($exitCode)->toBe(0)->and($this->store->defaultNode())->toBe('app-1');
        });
    });

    describe('clear sub-action (--clear)', function (): void {
        it('clears an existing default and reports was_set true', function (): void {
            $this->store->setDefaultNode('app-1');

            [$exitCode, $output] = runCommand($this, 'node:default', ['--clear' => true, '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['action'])
                ->toBe('clear')
                ->and($decoded['success']['data']['default_node'])
                ->toBeNull()
                ->and($decoded['success']['meta']['was_set'])
                ->toBeTrue()
                ->and($this->store->defaultNode())
                ->toBeNull();
        });

        it('is idempotent when no default is set and reports was_set false', function (): void {
            [$exitCode, $output] = runCommand($this, 'node:default', ['--clear' => true, '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['action'])
                ->toBe('clear')
                ->and($decoded['success']['data']['default_node'])
                ->toBeNull()
                ->and($decoded['success']['meta']['was_set'])
                ->toBeFalse();
        });

        it('does not require gateway reachability for clear', function (): void {
            $this->store->setDefaultNode('app-1');

            // No fakeGateway() call — if the command tried to contact the gateway it would throw
            [$exitCode] = runCommand($this, 'node:default', ['--clear' => true, '--json' => true]);

            expect($exitCode)->toBe(0);
        });
    });

    describe('human renderer', function (): void {
        it('renders show with a default as prose', function (): void {
            $this->store->setDefaultNode('app-1');

            [$exitCode, $output] = runCommand($this, 'node:default', []);

            expect($exitCode)
                ->toBe(0)
                ->and($output)
                ->toContain('Default node: app-1')
                ->and($output)
                ->not->toContain('action:')->and($output)
                ->not->toContain('{');
        });

        it('renders show with no default as empty-state prose with guidance', function (): void {
            [$exitCode, $output] = runCommand($this, 'node:default', []);

            expect($exitCode)
                ->toBe(0)
                ->and($output)
                ->toContain('No default node is set.')
                ->and($output)
                ->toContain('Run `orbit node:default <name>` to set one.')
                ->and($output)
                ->not->toContain('{');
        });

        it('renders set through a progress tree with a success footer', function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'nodes' => [
                    ['name' => 'app-1', 'role' => 'app-dev'],
                ],
            ]));

            [$exitCode, $output] = runCommand($this, 'node:default', ['name' => 'app-1']);

            expect($exitCode)
                ->toBe(0)
                ->and($output)
                ->toContain('Setting Default Node')
                ->and($output)
                ->toContain('Store local default')
                ->and($output)
                ->toContain('Default node set to app-1.')
                ->and($output)
                ->not->toContain('action:')->and($output)
                ->not->toContain('{')->and($this->store->defaultNode())->toBe('app-1');
        });

        it('renders set node-not-found failures as a failed tree footer', function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'nodes' => [
                    ['name' => 'app-2', 'role' => 'app-dev'],
                ],
            ]));

            [$exitCode, $output] = runCommand($this, 'node:default', ['name' => 'app-unknown']);

            expect($exitCode)
                ->toBe(1)
                ->and($output)
                ->toContain("Node 'app-unknown' not found or not visible.")
                ->and($output)
                ->not->toContain('"error"')->and($output)
                ->not->toContain('{');
        });

        it('renders set gateway-unavailable failures as prose', function (): void {
            fakeGatewayDown('connection refused');

            [$exitCode, $output] = runCommand($this, 'node:default', ['name' => 'app-1']);

            expect($exitCode)
                ->toBe(1)
                ->and($output)
                ->toContain('Gateway connection is required to set a default node.')
                ->and($output)
                ->not->toContain('"error"');
        });

        it('renders clear with an existing default as prose', function (): void {
            $this->store->setDefaultNode('app-1');

            [$exitCode, $output] = runCommand($this, 'node:default', ['--clear' => true]);

            expect($exitCode)
                ->toBe(0)
                ->and($output)
                ->toContain('Default node cleared.')
                ->and($output)
                ->not->toContain('action:')->and($output)
                ->not->toContain('{');
        });

        it('renders clear with no existing default as no-op prose', function (): void {
            [$exitCode, $output] = runCommand($this, 'node:default', ['--clear' => true]);

            expect($exitCode)
                ->toBe(0)
                ->and($output)
                ->toContain('No default node was set.')
                ->and($output)
                ->not->toContain('{');
        });
    });

    describe('mutually exclusive guard', function (): void {
        it('returns validation_failed when name and --clear are both provided', function (): void {
            [$exitCode, $output] = runCommand($this, 'node:default', [
                'name' => 'app-1',
                '--clear' => true,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($decoded['error']['code'])
                ->toBe('validation_failed')
                ->and($decoded['error']['meta']['fields'])
                ->toBe(['name', 'clear']);
        });
    });

    describe('JSON envelope shape', function (): void {
        it('show with default produces correct envelope shape', function (): void {
            $this->store->setDefaultNode('app-2');

            [, $output] = runCommand($this, 'node:default', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($decoded)
                ->toHaveKey('success')
                ->and($decoded['success'])
                ->toHaveKey('data')
                ->and($decoded['success']['data'])
                ->toHaveKey('action')
                ->and($decoded['success']['data'])
                ->toHaveKey('default_node');
        });

        it('show empty state produces default_node null', function (): void {
            [, $output] = runCommand($this, 'node:default', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($decoded['success']['data']['default_node'])->toBeNull();
        });

        it('clear produces was_set in success meta', function (): void {
            $this->store->setDefaultNode('app-1');

            [, $output] = runCommand($this, 'node:default', ['--clear' => true, '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($decoded['success']['meta'])->toHaveKey('was_set');
        });

        it('error envelope has code, message, meta keys', function (): void {
            [, $output] = runCommand($this, 'node:default', [
                'name' => 'x',
                '--clear' => true,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($decoded)
                ->toHaveKey('error')
                ->and($decoded['error'])
                ->toHaveKey('code')
                ->and($decoded['error'])
                ->toHaveKey('message')
                ->and($decoded['error'])
                ->toHaveKey('meta');
        });

        it('set success contains the canonical role without legacy environment', function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'nodes' => [
                    ['name' => 'app-1', 'role' => 'app-dev'],
                ],
            ]));

            [, $output] = runCommand($this, 'node:default', ['name' => 'app-1', '--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($decoded['success']['data']['default_node']['role'])
                ->toBe('app-dev')
                ->and($decoded['success']['data']['default_node'])
                ->not->toHaveKey('environment');
        });
    });
});
