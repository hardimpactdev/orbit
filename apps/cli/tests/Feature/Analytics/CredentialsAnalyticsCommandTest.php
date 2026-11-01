<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\StandardInputReader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Analytics\SetAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\ShowAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\UnsetAnalyticsCredentialsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\ArgvInput;
use Tests\Support\CapturedConsoleOutput;
use Tests\Support\FakeStandardInput;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-analytics-credentials-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->requestId = (string) Str::uuid();
    $this->answer = fn (bool $configured): MockResponse => MockResponse::make([
        'data' => ['configured' => $configured, 'driver' => 'plausible_ce', 'management_email_configured' => false, 'management_password_configured' => false],
        'meta' => ['request_id' => $this->requestId],
    ]);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('analytics:credentials', function (): void {
    it('reads the management password from stdin without echoing credentials', function (string $password, int $exit): void {
        $key = 'private-stats-sentinel';
        $email = 'private-owner@example.test';
        $mockClient = MockClient::global([SetAnalyticsCredentialsRequest::class => ($this->answer)(true)]);
        $input = new ArgvInput(['orbit', 'analytics:credentials', '--set', '--api-key='.$key, '--management-email='.$email, '--management-password-stdin', '--json']);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $password."\n");
        rewind($stream);
        $input->setStream($stream);
        app()->instance(StandardInputReader::class, FakeStandardInput::piped($password."\n"));
        $output = new CapturedConsoleOutput;
        try {
            expect(app(Kernel::class)->handle($input, $output))->toBe($exit);
            expect($output->stdout().$output->stderr())->not->toContain($key, $email);
            if ($exit === 0) {
                expect($output->stdout().$output->stderr())->not->toContain($password);
                expect($mockClient->getLastPendingRequest()?->body()->all())->toBe([
                    'api_key' => $key, 'management_email' => $email, 'management_password' => $password,
                ]);
            } else {
                expect($output->stdout().$output->stderr())->toContain('analytics.management_password_invalid');
                expect($mockClient->getLastPendingRequest())->toBeNull();
            }
        } finally {
            fclose($stream);
        }
    })->with(['valid' => ['private-password-sentinel', 0], 'spaces preserved' => ['  private-password-sentinel  ', 0], 'empty' => ['', 1], 'oversized' => [str_repeat('x', 4097), 1], 'control' => ["private\0sentinel", 1]]);

    it('refuses stdin mode on a terminal without reading or sending a request', function (): void {
        $stdin = FakeStandardInput::terminal();
        app()->instance(StandardInputReader::class, $stdin);
        $mockClient = MockClient::global();
        $this->artisan('analytics:credentials', ['--set' => true, '--api-key' => 'private-stats-sentinel', '--management-password-stdin' => true, '--json' => true])
            ->expectsOutputToContain('analytics.management_password_invalid')->assertExitCode(1);
        expect($stdin->reads)->toBe(0)->and($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('refuses a management password argv option', function (): void {
        $mockClient = MockClient::global();
        $this->artisan('analytics:credentials', ['--set' => true, '--management-password' => 'private-sentinel', '--json' => true])
            ->doesntExpectOutputToContain('private-sentinel')
            ->assertExitCode(1);
        expect($mockClient->getLastPendingRequest())->toBeNull();
    });
    it('returns whether a key is stored as JSON', function (): void {
        MockClient::global([ShowAnalyticsCredentialsRequest::class => ($this->answer)(false)]);

        $this->artisan('analytics:credentials', ['--json' => true])
            ->expectsOutput(json_encode([
                'configured' => false,
                'driver' => 'plausible_ce',
                'request_id' => $this->requestId,
                'management_email_configured' => false,
                'management_password_configured' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);
    });

    it('stores a key and never prints it', function (): void {
        $secret = 'plausible-stats-sentinel-key';
        $mockClient = MockClient::global([SetAnalyticsCredentialsRequest::class => ($this->answer)(true)]);

        $this->artisan('analytics:credentials', ['--set' => true, '--api-key' => $secret, '--json' => true])
            ->expectsOutput(json_encode([
                'configured' => true,
                'driver' => 'plausible_ce',
                'request_id' => $this->requestId,
                'management_email_configured' => false,
                'management_password_configured' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->body()->all())->toBe(['api_key' => $secret]);
    });

    it('names that a key is stored for a human, without the key', function (): void {
        $secret = 'plausible-stats-sentinel-key';
        MockClient::global([SetAnalyticsCredentialsRequest::class => ($this->answer)(true)]);

        $this->artisan('analytics:credentials', ['--set' => true, '--api-key' => $secret])
            ->expectsOutputToContain('The Plausible Stats API key is stored.')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(0);
    });

    it('clears the stored key', function (): void {
        MockClient::global([UnsetAnalyticsCredentialsRequest::class => ($this->answer)(false)]);

        $this->artisan('analytics:credentials', ['--unset' => true, '--json' => true])
            ->expectsOutput(json_encode([
                'configured' => false,
                'driver' => 'plausible_ce',
                'request_id' => $this->requestId,
                'management_email_configured' => false,
                'management_password_configured' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);
    });

    it('refuses --set and --unset together before any request', function (): void {
        $mockClient = MockClient::global();

        $this->artisan('analytics:credentials', ['--set' => true, '--unset' => true, '--json' => true])
            ->expectsOutputToContain('analytics.credentials_conflict')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('refuses a missing key without a terminal', function (): void {
        $mockClient = MockClient::global();

        $this->artisan('analytics:credentials', ['--set' => true, '--json' => true])
            ->expectsOutputToContain('analytics.api_key_invalid')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });
});
