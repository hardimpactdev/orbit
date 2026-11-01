<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Environment\ImportInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\SynchronizeInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\UpdateInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-environment-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('environment request wiring', function (): void {
    /** @param array{command:string,arguments:array<string,mixed>,request_class:class-string,path:string,body:array<string,mixed>,operation:string} $case */
    it('sends one typed request with the exact domain selector and operation body', function (array $case): void {
        $mock = MockClient::global([
            $case['request_class'] => environment_cli_response($case['operation']),
        ]);

        $this
            ->artisan($case['command'], $case['arguments'])
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf($case['request_class'])
            ->and($mock->getLastPendingRequest()?->getUrl())
            ->toBe("https://10.44.0.1{$case['path']}")
            ->and($mock->getLastRequest()?->body()->all())
            ->toBe($case['body'])
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    })->with([
        'import without replacement' => [[
            'command' => 'env:import',
            'arguments' => ['--instance' => 'app.com'],
            'request_class' => ImportInstanceEnvironmentRequest::class,
            'path' => '/api/v1/instances/app.com/environment/import',
            'body' => [],
            'operation' => 'import',
        ]],
        'import with replacement' => [[
            'command' => 'env:import',
            'arguments' => ['--instance' => 'app.com', '--replace' => true],
            'request_class' => ImportInstanceEnvironmentRequest::class,
            'path' => '/api/v1/instances/app.com/environment/import',
            'body' => ['replace' => true],
            'operation' => 'import',
        ]],
        'update' => [[
            'command' => 'env:update',
            'arguments' => ['--instance' => 'app.com', '--key' => 'APP_DEBUG', '--value' => 'true'],
            'request_class' => UpdateInstanceEnvironmentRequest::class,
            'path' => '/api/v1/instances/app.com/environment/APP_DEBUG',
            'body' => ['value' => 'true'],
            'operation' => 'update',
        ]],
    ]);

    it('accepts a numeric Instance ID for every operation', function (
        string $command,
        array $arguments,
        string $requestClass,
        string $operation,
        string $suffix,
    ): void {
        $mock = MockClient::global([
            $requestClass => environment_cli_response($operation),
        ]);

        $this
            ->artisan($command, ['--instance' => '17', ...$arguments])
            ->assertExitCode(0);

        expect($mock->getLastPendingRequest()?->getUrl())
            ->toBe("https://10.44.0.1/api/v1/instances/17/environment/{$suffix}")
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    })->with([
        'import' => ['env:import', [], ImportInstanceEnvironmentRequest::class, 'import', 'import'],
        'update' => [
            'env:update',
            ['--key' => 'APP_DEBUG', '--value' => 'true'],
            UpdateInstanceEnvironmentRequest::class,
            'update',
            'APP_DEBUG',
        ],
    ]);
});

describe('environment value preservation', function (): void {
    it('preserves the explicitly supplied update value as an exact string', function (string $value): void {
        $mock = MockClient::global([
            UpdateInstanceEnvironmentRequest::class => environment_cli_response('update'),
        ]);

        $this
            ->artisan('env:update', [
                '--instance' => 'app.com',
                '--key' => 'EXACT_VALUE',
                '--value' => $value,
            ])
            ->assertExitCode(0);

        expect($mock->getLastRequest()?->body()->all())->toBe(['value' => $value]);
    })->with([
        'empty string' => [''],
        'false string' => ['false'],
        'zero string' => ['0'],
        'newlines' => ["first\nsecond"],
        'Route domain placeholder' => ['https://{{instance.domain}}'],
    ]);

    it('refuses every missing required option before an HTTP request', function (
        string $command,
        array $arguments,
        string $code,
    ): void {
        $mock = MockClient::global();

        [$exitCode, $output] = environment_cli_display($command, [
            ...$arguments,
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim($output))
            ->toBe(environment_cli_error_json($code));
        expect($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'import selector' => ['env:import', [], 'env.instance_required'],
        'update selector' => ['env:update', ['--key' => 'APP_DEBUG', '--value' => 'true'], 'env.instance_required'],
        'update key' => ['env:update', ['--instance' => 'app.com', '--value' => 'true'], 'env.key_required'],
        'update value' => ['env:update', ['--instance' => 'app.com', '--key' => 'APP_DEBUG'], 'env.value_required'],
        'sync selector' => ['env:sync', [], 'env.instance_required'],
    ]);
});

describe('environment output', function (): void {
    it('renders a complete value-free human result and states that a store leaves the workload file unchanged', function (): void {
        MockClient::global([
            UpdateInstanceEnvironmentRequest::class => environment_cli_response('update'),
        ]);

        [$exit, $output] = environment_cli_display('env:update', [
            '--instance' => 'app.com',
            '--key' => 'PRIVATE_VALUE',
            '--value' => 'environment-secret-sentinel',
        ]);
        $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;
        expect($exit)->toBe(0);
        expect($flat)->toContain('Instance ID 17');
        expect($flat)->toContain('Operation update');
        expect($flat)->toContain('Changed true');
        expect($flat)->toContain('Stored keys 3');
        expect($flat)->toContain('Workload file unchanged');
        expect($output)->toContain(environment_cli_request_id());
        expect($output)->not->toContain('environment-secret-sentinel');
    });

    it('renders the exact value-free JSON store result', function (): void {
        MockClient::global([
            ImportInstanceEnvironmentRequest::class => environment_cli_response('import'),
        ]);

        $this
            ->artisan('env:import', ['--instance' => 'app.com', '--json' => true])
            ->expectsOutput(environment_cli_result_json('import', workloadFileChanged: false))
            ->assertExitCode(0);
    });
});

describe('environment failures', function (): void {
    it('renders a bounded Gateway failure with its request ID and without the submitted value', function (): void {
        MockClient::global([
            UpdateInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'env.configuration_invalid',
                        'message' => 'The complete Instance environment configuration is invalid.',
                        'details' => ['value' => 'environment-secret-sentinel'],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        [$exitCode, $output] = environment_cli_display('env:update', [
            '--instance' => 'app.com',
            '--key' => 'PRIVATE_VALUE',
            '--value' => 'environment-secret-sentinel',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim($output))
            ->toBe(environment_cli_error_json(
                code: 'env.configuration_invalid',
                message: 'The complete Instance environment configuration is invalid.',
                requestId: environment_cli_request_id(),
            ))
            ->not->toContain('environment-secret-sentinel')
            ->not->toContain('details')
            ->not->toContain('Gateway environment operation failed with HTTP status');
    });

    it('renders redacted configuration details for an invalid key and drops submitted values', function (): void {
        MockClient::global([
            UpdateInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'env.configuration_invalid',
                        'message' => 'The complete Instance environment configuration is invalid.',
                        'details' => [
                            'key' => 'BAD KEY',
                            'rule' => 'key',
                            'value' => 'environment-secret-sentinel',
                        ],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        [$exitCode, $output] = environment_cli_display('env:update', [
            '--instance' => 'app.com',
            '--key' => 'BAD KEY',
            '--value' => 'environment-secret-sentinel',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim($output))
            ->toBe(environment_cli_error_json(
                code: 'env.configuration_invalid',
                message: 'The complete Instance environment configuration is invalid.',
                requestId: environment_cli_request_id(),
                details: ['key' => 'BAD KEY', 'rule' => 'key'],
            ))
            ->not->toContain('environment-secret-sentinel');
    });

    it('renders redacted placeholder-rule details in human output', function (): void {
        MockClient::global([
            UpdateInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'env.configuration_invalid',
                        'message' => 'The complete Instance environment configuration is invalid.',
                        'details' => [
                            'key' => 'KEY',
                            'rule' => 'placeholder',
                            'placeholder' => '{{instance.domain}}',
                            'value' => 'environment-secret-sentinel',
                        ],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        [$exitCode, $output] = environment_cli_display('env:update', [
            '--instance' => 'app.com',
            '--key' => 'KEY',
            '--value' => 'environment-secret-sentinel',
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        $output = trim($output);
        expect($output)->toContain('The complete Instance environment configuration is invalid.');
        expect($output)->toContain('key: KEY');
        expect($output)->toContain('rule: placeholder');
        expect($output)->toContain('placeholder: {{instance.domain}}');
        expect($output)->toContain('Request ID: '.environment_cli_request_id());
        expect($output)->not->toContain('environment-secret-sentinel');
    });

    it('renders the Gateway import-conflict message instead of an HTTP status wrapper', function (): void {
        MockClient::global([
            ImportInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'env.import_conflict',
                        'message' => 'The import contains keys that are already stored.',
                        'details' => [],
                    ],
                ],
                409,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        [$exitCode, $output] = environment_cli_display('env:import', [
            '--instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim($output))
            ->toBe(environment_cli_error_json(
                code: 'env.import_conflict',
                message: 'The import contains keys that are already stored.',
                requestId: environment_cli_request_id(),
            ))
            ->not->toContain('Gateway environment operation failed with HTTP status');
    });

    it('renders the Gateway not-found message instead of an HTTP status wrapper', function (): void {
        MockClient::global([
            ImportInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'http.404',
                        'message' => 'Resource not found.',
                        'details' => [],
                    ],
                ],
                404,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        [$exitCode, $output] = environment_cli_display('env:import', [
            '--instance' => '999999',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim($output))
            ->toBe(environment_cli_error_json(
                code: 'http.404',
                message: 'Resource not found.',
                requestId: environment_cli_request_id(),
            ))
            ->not->toContain('Gateway environment operation failed with HTTP status');
    });

    it('renders a bounded transport failure without exception-chain diagnostics or the submitted value', function (): void {
        MockClient::global([
            UpdateInstanceEnvironmentRequest::class => static function (PendingRequest $pendingRequest): never {
                throw new FatalRequestException(
                    new RuntimeException('transport failed with environment-secret-sentinel'),
                    $pendingRequest,
                );
            },
        ]);

        [$exitCode, $output] = environment_cli_display('env:update', [
            '--instance' => 'app.com',
            '--key' => 'PRIVATE_VALUE',
            '--value' => 'environment-secret-sentinel',
            '--json' => true,
            '--no-interaction' => true,
            '--verbose' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim($output))
            ->toBe(environment_cli_error_json(
                code: 'gateway.request_failed',
                message: 'Gateway environment operation failed before receiving a response.',
            ))
            ->not->toContain('environment-secret-sentinel')
            ->not->toContain('FatalRequestException')
            ->not->toContain('RuntimeException');
    });
});

describe('environment synchronization', function (): void {
    it('follows the started operation and emits only the final Operation in JSON', function (string $selector): void {
        $mock = environment_cli_sync_mock('succeeded');

        $exit = Artisan::call('env:sync', ['--instance' => $selector, '--json' => true, '--no-interaction' => true]);
        $output = trim(Artisan::output());
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($data['id'])->toBe(901)
            ->and($data['kind'])->toBe('instance.sync_environment')
            ->and($data['status'])->toBe('succeeded')
            ->and($data['result'])->toBe(['instance_id' => 17, 'changed' => true, 'environment_changed' => true, 'key_count' => 3]);
        $mock->assertSent(fn ($request, $response): bool => $request instanceof SynchronizeInstanceEnvironmentRequest
            && $response->getPendingRequest()->getUrl() === "https://10.44.0.1/api/v1/instances/{$selector}/environment/sync"
            && (string) $response->getPendingRequest()->body() === '{}');
        $mock->assertSent(ShowOperationRequest::class);
    })->with(['Route domain' => 'app.com', 'Instance ID' => '17']);

    it('reports the outcome in human output', function (string $status, int $exitCode, string $message): void {
        environment_cli_sync_mock($status);

        [$exit, $output] = environment_cli_display('env:sync', ['--instance' => 'app.com']);

        expect($exit)->toBe($exitCode)
            ->and($output)->toContain($message, 'Operation: 901', environment_cli_request_id());
    })->with([
        'success' => ['succeeded', 0, 'Environment synchronization succeeded.'],
        'failure' => ['failed', 1, 'Environment synchronization failed.'],
    ]);

    it('reports the production runtime refresh and restarted Processes in human output', function (string $refresh, array $ids, string $message, string $restarted): void {
        environment_cli_sync_mock('succeeded', ['runtime_refresh' => $refresh, 'restarted_process_ids' => $ids]);

        [$exit, $output] = environment_cli_display('env:sync', ['--instance' => 'app.com']);

        expect($exit)->toBe(0)
            ->and($output)->toContain('Production PHP: '.$message, 'Processes restarted: '.$restarted);
    })->with([
        ['config_cache_and_opcache_refreshed', [12, 14], 'config cache rebuilt; FPM opcache reset', '12, 14'],
        ['opcache_refreshed_config_uncached', [12], 'FPM opcache reset; config remains uncached', '12'],
        ['skipped_development', [], 'refresh skipped: development Instance', 'none'],
        ['skipped_no_php_version', [], 'refresh skipped: no PHP version selected', 'none'],
        ['skipped_no_current_release', [], 'refresh skipped: no current release', 'none'],
    ]);

    it('exits nonzero and prints the failed Operation in JSON', function (): void {
        environment_cli_sync_mock('failed');

        $exit = Artisan::call('env:sync', ['--instance' => 'app.com', '--json' => true, '--no-interaction' => true]);
        $data = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($data['status'])->toBe('failed')
            ->and($data['failed_step'])->toBe('write')
            ->and($data['error_code'])->toBe('env.write_failed');
    });
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function environment_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}

/** Records the Gateway's 202 Operation contract for `env:sync`, then a terminal Operation for the follower. */
/** @param array<string, mixed> $result */
function environment_cli_sync_mock(string $status, array $result = []): MockClient
{
    $started = ['id' => 901, 'kind' => 'instance.sync_environment', 'target_type' => 'instance', 'target_id' => 17,
        'input' => ['instance_id' => 17], 'status' => 'running', 'sequence' => 1, 'current_step' => null, 'failed_step' => null,
        'error_code' => null, 'result' => null, 'cancel_requested' => false, 'attempts' => 0, 'poll_after_seconds' => 0];
    $final = [...$started, 'status' => $status, 'sequence' => 6, ...($status === 'succeeded'
        ? ['result' => ['instance_id' => 17, 'changed' => true, 'environment_changed' => true, 'key_count' => 3, ...$result]]
        : ['failed_step' => 'write', 'error_code' => 'env.write_failed'])];

    return MockClient::global([
        SynchronizeInstanceEnvironmentRequest::class => MockResponse::make(['data' => $started, 'meta' => ['request_id' => environment_cli_request_id()]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final, 'meta' => ['request_id' => environment_cli_request_id()]]),
    ]);
}

function environment_cli_response(string $operation, bool $changed = true): MockResponse
{
    return MockResponse::make([
        'data' => [
            'instance_id' => 17,
            'operation' => $operation,
            'changed' => $changed,
            'key_count' => 3,
        ],
        'meta' => ['request_id' => environment_cli_request_id()],
    ]);
}

function environment_cli_result_json(string $operation, ?bool $workloadFileChanged = null): string
{
    $payload = [
        'instance_id' => 17,
        'operation' => $operation,
        'changed' => true,
        'key_count' => 3,
        'request_id' => environment_cli_request_id(),
    ];

    if ($workloadFileChanged !== null) {
        $payload['workload_file_changed'] = $workloadFileChanged;
    }

    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @param array<string, string> $details */
function environment_cli_error_json(
    string $code,
    string $message = 'Command input is invalid.',
    ?string $requestId = null,
    array $details = [],
): string {
    $messages = [
        'env.instance_required' => 'Instance ID or Route domain is required.',
        'env.key_required' => 'Environment key is required.',
        'env.value_required' => 'Environment value is required.',
    ];

    $error = [
        'code' => $code,
        'message' => $messages[$code] ?? $message,
    ];

    if ($details !== []) {
        $error['details'] = $details;
    }

    $error['request_id'] = $requestId;

    return json_encode(['error' => $error], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function environment_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
