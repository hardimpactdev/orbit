<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Vault\SetupVaultRequest;
use Orbit\Sdk\Requests\Vault\ShowVaultStatusRequest;
use Orbit\Sdk\Requests\Vault\SyncVaultRequest;
use Orbit\Sdk\Requests\Vault\TeardownVaultRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    $this->callerOrbitHome = config('orbit.home');
    putenv('COLUMNS=200');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-vault-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    if (! is_dir($this->orbitHome)) {
        mkdir($this->orbitHome, 0700, true);
    }
    $this->clientSecretFile = vault_cli_secret_file($this->orbitHome.'/client-secret', "vault-client-secret-sentinel\n");
    $this->masterPasswordFile = vault_cli_secret_file($this->orbitHome.'/master-password', "  vault-master-password-sentinel \n");
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    config()->set('orbit.home', $this->callerOrbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

describe('vault:setup', function (): void {
    it('sends the secrets from files without their final newline and never prints them', function (): void {
        $mock = MockClient::global([SetupVaultRequest::class => vault_cli_status_response()]);

        [$exit, $output] = vault_cli_display('vault:setup', [
            ...vault_cli_setup_options($this),
            '--json' => true,
        ]);

        expect($exit)->toBe(0)
            ->and(json_decode($output, true))->toBe([...vault_cli_status_payload(), 'request_id' => vault_cli_request_id()])
            ->and($output)->not->toContain('vault-client-secret-sentinel', 'vault-master-password-sentinel')
            ->and($mock->getLastPendingRequest()->body()->all())->toBe([
                'url' => 'https://vault.orbit',
                'client_id' => 'user.3f0c',
                'client_secret' => 'vault-client-secret-sentinel',
                'master_password' => '  vault-master-password-sentinel ',
                'organization' => 'Orbit',
                'collection' => 'Databases',
            ]);
    });

    it('renders the status for a human', function (): void {
        MockClient::global([SetupVaultRequest::class => vault_cli_status_response()]);

        [$exit, $output] = vault_cli_display('vault:setup', vault_cli_setup_options($this));

        expect($exit)->toBe(0)
            ->and($output)->toContain(
                'Vaultwarden publishing is set up.',
                'https://vault.orbit',
                'gateway@orbit.test',
                'Orbit (org-1)',
                'Databases (col-1)',
                'database-user:4',
                'password_unknown',
                '2026-10-11T12:00:00Z',
                vault_cli_request_id(),
            )
            ->and($output)->not->toContain('vault-client-secret-sentinel', 'vault-master-password-sentinel');
    });

    it('refuses a URL that is not an HTTPS origin', function (?string $url): void {
        $mock = MockClient::global();

        [$exit, $output] = vault_cli_display('vault:setup', [
            ...vault_cli_setup_options($this),
            '--url' => $url,
            '--json' => true,
        ]);

        expect($exit)->toBe(1)
            ->and(json_decode($output, true)['error']['code'])->toBe('vault.url_invalid')
            ->and(json_decode($output, true)['error']['details'] ?? null)->toBe(['field' => 'url'])
            ->and($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'missing' => [null],
        'http' => ['http://vault.orbit'],
        'path' => ['https://vault.orbit/admin'],
        'credentials' => ['https://user:pass@vault.orbit'],
        'not a url' => ['vault.orbit'],
    ]);

    it('requires the client ID, organization, and collection', function (string $option): void {
        $mock = MockClient::global();

        [$exit, $output] = vault_cli_display('vault:setup', [
            ...vault_cli_setup_options($this),
            "--{$option}" => null,
            '--json' => true,
        ]);

        expect($exit)->toBe(1)
            ->and(json_decode($output, true)['error']['code'])->toBe('input.invalid')
            ->and(json_decode($output, true)['error']['details'] ?? null)->toBe(['field' => $option])
            ->and($mock->getLastPendingRequest())->toBeNull();
    })->with(['client-id', 'organization', 'collection']);

    it('requires each secret file', function (string $option): void {
        $mock = MockClient::global();

        [$exit, $output] = vault_cli_display('vault:setup', [
            ...vault_cli_setup_options($this),
            "--{$option}" => null,
            '--json' => true,
        ]);

        expect($exit)->toBe(1)
            ->and(json_decode($output, true)['error']['code'])->toBe('vault.secret_required')
            ->and(json_decode($output, true)['error']['details'] ?? null)->toBe(['field' => $option])
            ->and($mock->getLastPendingRequest())->toBeNull();
    })->with(['client-secret-file', 'master-password-file']);

    it('refuses an unreadable or empty secret file', function (string $option, string $contents): void {
        $mock = MockClient::global();
        $path = $this->orbitHome.'/bad-secret';

        if ($contents !== 'missing') {
            vault_cli_secret_file($path, $contents);
        }

        [$exit, $output] = vault_cli_display('vault:setup', [
            ...vault_cli_setup_options($this),
            "--{$option}" => $path,
            '--json' => true,
        ]);

        expect($exit)->toBe(1)
            ->and(json_decode($output, true)['error']['code'])->toBe('vault.secret_invalid')
            ->and(json_decode($output, true)['error']['details'] ?? null)->toBe(['field' => $option])
            ->and($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'missing client secret' => ['client-secret-file', 'missing'],
        'empty client secret' => ['client-secret-file', " \n"],
        'missing master password' => ['master-password-file', 'missing'],
        'empty master password' => ['master-password-file', ''],
    ]);

    it('preserves a Gateway refusal and its request ID', function (): void {
        MockClient::global([SetupVaultRequest::class => MockResponse::make([
            'status' => 422,
            'error' => [
                'code' => 'vault.login_failed',
                'message' => 'Vaultwarden refused the API key.',
                'details' => [],
            ],
            'meta' => ['request_id' => vault_cli_request_id()],
        ], 422, ['X-Orbit-Request-Id' => vault_cli_request_id()])]);

        [$exit, $output] = vault_cli_display('vault:setup', [
            ...vault_cli_setup_options($this),
            '--json' => true,
        ]);

        expect($exit)->toBe(1)
            ->and(json_decode($output, true)['error']['code'])->toBe('vault.login_failed')
            ->and($output)->not->toContain('vault-client-secret-sentinel', 'vault-master-password-sentinel');
    });
});

describe('vault:status and vault:sync', function (): void {
    it('returns the status as json', function (string $command, string $request): void {
        $mock = MockClient::global([$request => vault_cli_status_response()]);

        [$exit, $output] = vault_cli_display($command, ['--json' => true]);

        expect($exit)->toBe(0)
            ->and(json_decode($output, true))->toBe([...vault_cli_status_payload(), 'request_id' => vault_cli_request_id()])
            ->and($mock->getLastPendingRequest()?->getRequest())->toBeInstanceOf($request);
    })->with([
        'status' => ['vault:status', ShowVaultStatusRequest::class],
        'sync' => ['vault:sync', SyncVaultRequest::class],
    ]);

    it('renders a status that is not set up', function (): void {
        MockClient::global([ShowVaultStatusRequest::class => vault_cli_status_response(vault_cli_unconfigured_payload())]);

        [$exit, $output] = vault_cli_display('vault:status');

        expect($exit)->toBe(0)
            ->and($output)->toContain('Vault status.', 'Configured', 'no')
            ->and($output)->not->toContain('SOURCE');
    });

    it('renders the last error', function (): void {
        MockClient::global([SyncVaultRequest::class => vault_cli_status_response([
            ...vault_cli_status_payload(),
            'last_error' => ['code' => 'vault.unreachable', 'message' => 'Vaultwarden did not answer.'],
        ])]);

        [$exit, $output] = vault_cli_display('vault:sync');

        expect($exit)->toBe(0)
            ->and($output)->toContain('vault.unreachable: Vaultwarden did not answer.');
    });

    it('preserves vault.not_configured from sync', function (): void {
        MockClient::global([SyncVaultRequest::class => MockResponse::make([
            'status' => 409,
            'error' => ['code' => 'vault.not_configured', 'message' => 'Vaultwarden is not set up.', 'details' => []],
            'meta' => ['request_id' => vault_cli_request_id()],
        ], 409, ['X-Orbit-Request-Id' => vault_cli_request_id()])]);

        [$exit, $output] = vault_cli_display('vault:sync', ['--json' => true]);

        expect($exit)->toBe(1)
            ->and(json_decode($output, true)['error']['code'])->toBe('vault.not_configured');
    });
});

describe('vault:teardown', function (): void {
    it('tears down with explicit consent', function (array $options, bool $keepItems): void {
        $mock = MockClient::global([
            TeardownVaultRequest::class => vault_cli_status_response(vault_cli_unconfigured_payload()),
        ]);

        [$exit, $output] = vault_cli_display('vault:teardown', [...$options, '--yes' => true, '--json' => true]);

        expect($exit)->toBe(0)
            ->and(json_decode($output, true)['configured'])->toBeFalse()
            ->and($mock->getLastPendingRequest()->body()->all())->toBe(['keep_items' => $keepItems]);
    })->with([
        'delete items' => [[], false],
        'keep items' => [['--keep-items' => true], true],
    ]);

    it('refuses teardown without --yes when it cannot prompt', function (array $options, string $expected): void {
        $mock = MockClient::global();

        [$exit, $output] = vault_cli_display('vault:teardown', $options);

        expect($exit)->toBe(1)
            ->and($output)->toContain($expected)
            ->and($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'json' => [['--json' => true], '"code":"input.confirmation_required"'],
        'noninteractive human' => [[], 'Non-interactive vault teardown requires --yes.'],
    ]);

    it('asks a default-No question and sends nothing unless the operator accepts', function (array $keys, bool $accepted): void {
        $result = vault_cli_consent(
            ['vault:teardown', '--no-ansi'],
            $keys,
            $accepted ? [[
                'class' => TeardownVaultRequest::class,
                'body' => ['data' => vault_cli_unconfigured_payload(), 'meta' => ['request_id' => vault_cli_request_id()]],
            ]] : [],
        );

        expect($result['status'])->toBe($accepted ? 0 : 1)
            ->and($result['prompt_seen'])->toBeTrue()
            ->and($result['output'])->toContain('permanently deletes every item Orbit published')
            ->and($result['requests'])->toHaveCount($accepted ? 1 : 0);

        if ($accepted) {
            expect($result['requests'][0]['body'])->toBe(['keep_items' => false]);
        } else {
            expect($result['output'])->toContain('Vault teardown cancelled.');
        }
    })->with([
        'default No' => [["\r"], false],
        'Yes' => [['y', "\r"], true],
    ]);
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function vault_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}

/** @return array<string, string> */
function vault_cli_setup_options(object $test): array
{
    return [
        '--url' => 'https://vault.orbit',
        '--client-id' => 'user.3f0c',
        '--client-secret-file' => $test->clientSecretFile,
        '--master-password-file' => $test->masterPasswordFile,
        '--organization' => 'Orbit',
        '--collection' => 'Databases',
    ];
}

/**
 * @param  list<string>  $arguments
 * @param  list<string>  $keys
 * @param  list<array<string, mixed>>  $replies
 * @return array<string, mixed>
 */
function vault_cli_consent(array $arguments, array $keys, array $replies): array
{
    $directory = sys_get_temp_dir().'/orbit-vault-consent-'.Str::uuid();
    new Filesystem()->makeDirectory($directory);
    $configuration = [
        'home' => $directory.'/home',
        'trace' => $directory.'/requests.jsonl',
        'keys' => array_map(base64_encode(...), $keys),
        'arguments' => $arguments,
        'replies' => $replies,
        'prompt' => 'Tear down Vaultwarden publishing?',
        'columns' => 200,
    ];
    file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $fixtures = dirname(__DIR__, 2).'/Fixtures/Console/';

    try {
        $process = new Process(
            ['python3', $fixtures.'command-consent-pty.py', PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json'],
            env: ['PAO_DISABLE' => '1'],
        );
        $process->setTimeout(15);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $result['output'] = base64_decode((string) $result['raw']);
        $result['requests'] = is_file($configuration['trace']) ? array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($configuration['trace'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        ) : [];

        return $result;
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
}

function vault_cli_secret_file(string $path, string $contents): string
{
    file_put_contents($path, $contents);
    chmod($path, 0600);

    return $path;
}

/** @return array<string, mixed> */
function vault_cli_status_payload(): array
{
    return [
        'configured' => true,
        'url' => 'https://vault.orbit',
        'email' => 'gateway@orbit.test',
        'organization' => ['id' => 'org-1', 'name' => 'Orbit'],
        'collection' => ['id' => 'col-1', 'name' => 'Databases'],
        'items' => 3,
        'pending' => 1,
        'skipped' => [
            ['source' => 'database-user:4', 'slug' => 'shop', 'username' => 'reader', 'reason' => 'password_unknown'],
        ],
        'last_synced_at' => '2026-10-11T12:00:00Z',
        'last_error' => null,
    ];
}

/** @return array<string, mixed> */
function vault_cli_unconfigured_payload(): array
{
    return [
        'configured' => false,
        'url' => null,
        'email' => null,
        'organization' => null,
        'collection' => null,
        'items' => 0,
        'pending' => 0,
        'skipped' => [],
        'last_synced_at' => null,
        'last_error' => null,
    ];
}

/** @param array<string, mixed>|null $payload */
function vault_cli_status_response(?array $payload = null): MockResponse
{
    return MockResponse::make([
        'data' => $payload ?? vault_cli_status_payload(),
        'meta' => ['request_id' => vault_cli_request_id()],
    ], 200, ['X-Orbit-Request-Id' => vault_cli_request_id()]);
}

function vault_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
