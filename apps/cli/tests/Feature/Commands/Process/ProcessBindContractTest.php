<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('process managed service bind contract', function (): void {
    it('posts normalized dual binds for node-owned docker managed services on add', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'valkey',
                'node' => 'database-1',
                'runtime' => 'docker',
            ],
            'runtime_units' => [['name' => 'orbit-valkey', 'context' => 'node']],
        ], ['warnings' => []]));

        [$exitCode] = runCommand($this, 'process:add', [
            'name' => 'valkey',
            '--node' => 'database-1',
            '--service' => 'valkey',
            '--runtime' => 'docker',
            '--bind' => ['loopback', 'wireguard', 'wireguard'],
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && $request->data() === [
                    'node' => 'database-1',
                    'name' => 'valkey',
                    'restart_policy' => 'never',
                    'crash_notification' => 'none',
                    'start' => true,
                    'runtime' => 'docker',
                    'service' => 'valkey',
                    'binds' => ['wireguard', 'loopback'],
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('omits binds from the add payload when --bind is not supplied', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'valkey',
                'node' => 'database-1',
                'runtime' => 'docker',
            ],
            'runtime_units' => [],
        ], ['warnings' => []]));

        [$exitCode] = runCommand($this, 'process:add', [
            'name' => 'valkey',
            '--node' => 'database-1',
            '--service' => 'valkey',
            '--runtime' => 'docker',
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/processes'
                && ! array_key_exists('binds', $data)
                && ($data['service'] ?? null) === 'valkey'
            );
        });

        expect($exitCode)->toBe(0);
    });

    it('patches normalized binds for process:update', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'process' => [
                'name' => 'valkey',
                'node' => 'database-1',
                'runtime' => 'docker',
            ],
            'changed' => ['binds'],
            'runtime_units' => [],
        ], ['warnings' => []]));

        [$exitCode] = runCommand($this, 'process:update', [
            'name' => 'valkey',
            '--node' => 'database-1',
            '--bind' => ['loopback', 'wireguard'],
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PATCH'
                && $request->url() === 'https://gateway.test/api/processes/valkey'
                && $request->data() === [
                    'node' => 'database-1',
                    'binds' => ['wireguard', 'loopback'],
                    'restart' => false,
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('rejects empty unsupported and out-of-scope binds before gateway IO', function (
        string $command,
        array $arguments,
        string $field,
        ?string $reason = null,
    ): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            '--json' => true,
            ...$arguments,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe($field);

        if ($reason !== null) {
            expect($decoded['error']['meta']['reason'] ?? null)->toBe($reason);
        }
    })->with([
        'empty bind on add' => [
            'process:add',
            [
                'name' => 'valkey',
                '--node' => 'database-1',
                '--service' => 'valkey',
                '--bind' => [''],
            ],
            'bind',
            'required',
        ],
        'unsupported bind on add' => [
            'process:add',
            [
                'name' => 'valkey',
                '--node' => 'database-1',
                '--service' => 'valkey',
                '--bind' => ['0.0.0.0'],
            ],
            'bind',
            'unsupported_value',
        ],
        'host command bind on add' => [
            'process:add',
            [
                'name' => 'worker',
                'process_command' => 'php artisan queue:work',
                '--instance' => 'docs.production',
                '--bind' => ['wireguard'],
            ],
            'bind',
            'process_bind_requires_node_docker_service',
        ],
        'docker swarm bind on add' => [
            'process:add',
            [
                'name' => 'valkey',
                '--node' => 'database-1',
                '--service' => 'valkey',
                '--runtime' => 'docker-swarm',
                '--bind' => ['loopback'],
            ],
            'bind',
            'process_bind_requires_docker_runtime',
        ],
        'empty bind on update' => [
            'process:update',
            [
                'name' => 'valkey',
                '--node' => 'database-1',
                '--bind' => [''],
            ],
            'bind',
            'required',
        ],
        'instance scope bind on update' => [
            'process:update',
            [
                'name' => 'queue',
                '--instance' => 'docs.production',
                '--bind' => ['wireguard'],
            ],
            'bind',
            'process_bind_requires_node_docker_service',
        ],
    ]);
});
