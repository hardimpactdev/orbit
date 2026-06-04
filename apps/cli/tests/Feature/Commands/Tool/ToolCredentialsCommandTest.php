<?php

declare(strict_types=1);

use App\Commands\Tool\ToolCredentialsCommand;
use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

describe('tool:credentials', function (): void {
    it('returns gateway credential fields verbatim in JSON mode without CLI-side mutation', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'credentials' => [
                'tool' => 'openclaw',
                'node' => 'app-1',
                'fields' => [
                    'host' => 'orbit.test',
                    'password' => 'gateway-managed-secret',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:credentials', [
            'tool' => 'openclaw',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/tools/openclaw/credentials')
                && str_contains($url, 'node=app-1');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['credentials']['fields']['password'])->toBe('gateway-managed-secret')
            ->and($output)->not->toContain('operation_token')
            ->and($output)->not->toContain('executor_secret');
    });

    it('does not emit extra sensitive gateway envelope fields in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'credentials' => [
                'tool' => 'openclaw',
                'node' => 'app-1',
                'fields' => [
                    'password' => 'gateway-managed-secret',
                ],
            ],
            'operation_token' => 'must-not-leak',
        ], [
            'executor_secret' => 'must-not-leak',
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:credentials', [
            'tool' => 'openclaw',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data'])->toHaveKey('credentials')
            ->and($decoded['success']['data'])->not->toHaveKey('operation_token')
            ->and($decoded['success']['meta'])->toBe([])
            ->and($output)->not->toContain('must-not-leak');
    });

    it('renders human credential fields from the gateway payload only', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'credentials' => [
                'tool' => 'openclaw',
                'node' => 'app-1',
                'fields' => [
                    'host' => 'orbit.test',
                    'password' => 'gateway-managed-secret',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:credentials', [
            'tool' => 'openclaw',
            '--node' => 'app-1',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Credentials for openclaw on app-1:')
            ->and($output)->toContain('host: orbit.test')
            ->and($output)->toContain('password: gateway-managed-secret')
            ->and($output)->not->toContain('operation_token');
    });

    it('uses the local default node when no target option is provided', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-tool-credentials-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'credentials' => [
                'tool' => 'openclaw',
                'node' => 'default-app',
                'fields' => [
                    'password' => 'gateway-managed-secret',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:credentials', [
            'tool' => 'openclaw',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/tools/openclaw/credentials')
                && str_contains($url, 'node=default-app');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['credentials']['node'])->toBe('default-app');

        @unlink($store->path());
    });

    it('prompts for a visible tool when interactive tool input is omitted', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-tool-credentials-interactive-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $query = parse_url($request->url(), PHP_URL_QUERY) ?? '';
            parse_str($query, $parameters);

            if ($request->method() === 'GET' && $path === '/api/tools') {
                return Http::response(fakeSuccessEnvelope([
                    'tools' => [
                        [
                            'name' => 'openclaw',
                            'node' => 'app-1',
                        ],
                    ],
                ]));
            }

            if (
                $request->method() === 'GET'
                && $path === '/api/tools/openclaw/credentials'
                && ($parameters['node'] ?? null) === 'app-1'
            ) {
                return Http::response(fakeSuccessEnvelope([
                    'credentials' => [
                        'tool' => 'openclaw',
                        'node' => 'app-1',
                        'fields' => [
                            'password' => 'gateway-managed-secret',
                        ],
                    ],
                ]));
            }

            return Http::response(fakeErrorEnvelope('unexpected_request', 'Unexpected gateway request.'), 500);
        });

        $command = app(ToolCredentialsCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $tester->setInputs(['openclaw']);

        $exitCode = $tester->execute([]);

        Http::assertSentCount(2);

        expect($exitCode)->toBe(0)
            ->and($tester->getDisplay())->toContain('gateway-managed-secret');

        @unlink($store->path());
    });

    it('uses the local default node before prompting for an omitted interactive tool', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-tool-credentials-interactive-default-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $query = parse_url($request->url(), PHP_URL_QUERY) ?? '';
            parse_str($query, $parameters);

            if (
                $request->method() === 'GET'
                && $path === '/api/tools'
                && ($parameters['node'] ?? null) === 'default-app'
            ) {
                return Http::response(fakeSuccessEnvelope([
                    'tools' => [
                        [
                            'name' => 'openclaw',
                            'node' => 'default-app',
                        ],
                    ],
                ]));
            }

            if (
                $request->method() === 'GET'
                && $path === '/api/tools/openclaw/credentials'
                && ($parameters['node'] ?? null) === 'default-app'
            ) {
                return Http::response(fakeSuccessEnvelope([
                    'credentials' => [
                        'tool' => 'openclaw',
                        'node' => 'default-app',
                        'fields' => [
                            'password' => 'gateway-managed-secret',
                        ],
                    ],
                ]));
            }

            return Http::response(fakeErrorEnvelope('unexpected_request', 'Unexpected gateway request.'), 500);
        });

        $command = app(ToolCredentialsCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $tester->setInputs(['openclaw']);

        $exitCode = $tester->execute([]);

        Http::assertSentCount(2);

        expect($exitCode)->toBe(0)
            ->and($tester->getDisplay())->toContain('gateway-managed-secret');

        @unlink($store->path());
    });

    it('fails validation before opening the gateway request when tool is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'tool:credentials', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('tool');
    });

    it('passes through authorization failures from the gateway without remapping them', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'This node is not authorized to manage tools.'), 403);

        [$exitCode, $output] = runCommand($this, 'tool:credentials', [
            'tool' => 'openclaw',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed');
    });

    it('passes through unsupported credential reads from the gateway', function (): void {
        fakeGateway(fakeErrorEnvelope('tool.unsupported_action', "Tool 'caddy' does not support credentials.", [
            'tool' => 'caddy',
            'action' => 'credentials',
        ]), 400);

        [$exitCode, $output] = runCommand($this, 'tool:credentials', [
            'tool' => 'caddy',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('tool.unsupported_action');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'tool:credentials', [
            'tool' => 'openclaw',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
