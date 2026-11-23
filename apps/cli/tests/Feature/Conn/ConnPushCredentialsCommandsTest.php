<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Conn\SetConnPushCredentialsRequest;
use Orbit\Sdk\Requests\Conn\ShowConnPushCredentialsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const PushCredentialsRequestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a846';

/**
 * Runs a CLI command with an explicit stdin stream without touching the real STDIN.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{exit: int, output: string}
 */
function run_cli_with_stdin(string $command, array $parameters, string $stdin): array
{
    $stream = fopen('php://memory', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput(['command' => $command, '--no-interaction' => true, ...$parameters]);
    $input->setStream($stream);
    $output = new BufferedOutput;

    $exit = app(ConsoleKernel::class)->handle($input, $output);
    fclose($stream);

    return ['exit' => $exit, 'output' => $output->fetch()];
}

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-conn-push-credentials-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/unused-ca'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('conn:push-credentials:show', function (): void {
    it('returns configured status, key_id and team_id in JSON and human modes', function (bool $configured, ?string $keyId, ?string $teamId, string $expectedHuman): void {
        MockClient::global([
            ShowConnPushCredentialsRequest::class => MockResponse::make([
                'data' => [
                    'configured' => $configured,
                    'key_id' => $keyId,
                    'team_id' => $teamId,
                ],
                'meta' => ['request_id' => PushCredentialsRequestId],
            ]),
        ]);

        expect(Artisan::call('conn:push-credentials:show', ['--json' => true, '--no-interaction' => true]))->toBe(0)
            ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'configured' => $configured,
                'key_id' => $keyId,
                'team_id' => $teamId,
                'request_id' => PushCredentialsRequestId,
            ]);

        expect(Artisan::call('conn:push-credentials:show', ['--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain($expectedHuman);
    })->with([
        'unconfigured' => [false, null, null, 'No Conn APNs push credentials are stored.'],
        'configured' => [true, 'AB12CD34EF', 'TEAM987654', 'Conn APNs push credentials are stored.'],
    ]);
});

describe('conn:push-credentials:set', function (): void {
    it('reads the .p8 private key from stdin, sends PUT, and never prints the key', function (): void {
        $secret = "-----BEGIN PRIVATE KEY-----\nSENTINEL-CLI-KEY-BODY\n-----END PRIVATE KEY-----\n";
        $mock = MockClient::global([
            SetConnPushCredentialsRequest::class => MockResponse::make([
                'data' => [
                    'configured' => true,
                    'key_id' => 'AB12CD34EF',
                    'team_id' => 'TEAM987654',
                ],
                'meta' => ['request_id' => PushCredentialsRequestId],
            ]),
        ]);

        $jsonRun = run_cli_with_stdin('conn:push-credentials:set', [
            '--key-id' => 'AB12CD34EF',
            '--team-id' => 'TEAM987654',
            '--json' => true,
        ], $secret);

        expect($jsonRun['exit'])->toBe(0)
            ->and(json_decode(trim($jsonRun['output']), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'configured' => true,
                'key_id' => 'AB12CD34EF',
                'team_id' => 'TEAM987654',
                'request_id' => PushCredentialsRequestId,
            ])
            ->and($jsonRun['output'])->not->toContain('SENTINEL-CLI-KEY-BODY')
            ->and($mock->getLastPendingRequest()?->body()->all())->toBe([
                'key_id' => 'AB12CD34EF',
                'team_id' => 'TEAM987654',
                'private_key' => trim($secret),
            ]);

        $humanRun = run_cli_with_stdin('conn:push-credentials:set', [
            '--key-id' => 'AB12CD34EF',
            '--team-id' => 'TEAM987654',
        ], $secret);

        expect($humanRun['exit'])->toBe(0)
            ->and($humanRun['output'])->toContain('Conn APNs push credentials are stored.')
            ->and($humanRun['output'])->toContain('AB12CD34EF')
            ->and($humanRun['output'])->toContain('TEAM987654')
            ->and($humanRun['output'])->not->toContain('SENTINEL-CLI-KEY-BODY');
    });

    it('refuses invalid key-id, team-id, or empty stdin before calling the Gateway', function (): void {
        $mock = MockClient::global([]);

        $badKey = run_cli_with_stdin('conn:push-credentials:set', [
            '--key-id' => 'bad key!',
            '--team-id' => 'TEAM987654',
            '--json' => true,
        ], 'sentinel-key');
        expect($badKey['exit'])->toBe(1)
            ->and($badKey['output'])->toContain('conn.push_key_id_invalid')
            ->and($badKey['output'])->not->toContain('sentinel-key');

        $badTeam = run_cli_with_stdin('conn:push-credentials:set', [
            '--key-id' => 'AB12CD34EF',
            '--team-id' => '',
            '--json' => true,
        ], 'sentinel-key');
        expect($badTeam['exit'])->toBe(1)
            ->and($badTeam['output'])->toContain('conn.push_team_id_invalid')
            ->and($badTeam['output'])->not->toContain('sentinel-key');

        $emptyStdin = run_cli_with_stdin('conn:push-credentials:set', [
            '--key-id' => 'AB12CD34EF',
            '--team-id' => 'TEAM987654',
            '--json' => true,
        ], "   \n");
        expect($emptyStdin['exit'])->toBe(1)
            ->and($emptyStdin['output'])->toContain('conn.push_private_key_invalid');

        $mock->assertNothingSent();
    });
});
