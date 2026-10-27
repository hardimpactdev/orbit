<?php

declare(strict_types=1);

use App\Actions\Tasks\CheckSwampReaderCredentialAction;
use App\Actions\Tasks\InstallSwampReaderCredentialAction;
use App\Console\Commands\CheckSwampReaderCredentialCommand;
use App\Console\Commands\InstallSwampReaderCredentialCommand;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\SwampReaderCredential;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $this->travelTo('2026-10-27 12:00:00');
    $base = storage_path('reader-test');
    mkdir($base.'/shared', 0700, true);
    config(['orbit.gateway_checkout' => $base.'/orbit/apps/gateway']);
    app()->instance(GatewayReleaseLock::class, new GatewayReleaseLock($base.'/release.lock'));
    $this->envPath = $base.'/shared/gateway.env';
    $this->original = "# Preserve other settings\nAPP_NAME=Orbit\nUNCHANGED='value with spaces'\nORBIT_SWAMP_ADDRESS=192.0.2.17\nORBIT_SWAMP_CA_PATH=/test/ca.pem\nORBIT_SWAMP_READ_TOKEN=old.fixture-reader-token\nORBIT_SWAMP_READ_TOKEN_NAME=orbit-gateway-read-old\nORBIT_SWAMP_READ_TOKEN_EXPIRES_AT=2026-11-26T12:00:00Z\n";
    file_put_contents($this->envPath, $this->original);
    chmod($this->envPath, 0600);
    $this->reveal = ['name' => 'orbit-gateway-read-new', 'token' => 'new.fixture-reader-token', 'expired' => false];
});

/** @return array{int, string} */
function install_reader_input(string $json, string $expiry = '2026-11-26T12:00:00Z'): array
{
    $input = new ArrayInput(['--expires-at' => $expiry]);
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $json);
    rewind($stream);
    $input->setStream($stream);
    $output = new BufferedOutput;
    $command = app(InstallSwampReaderCredentialCommand::class);
    $command->setLaravel(app());
    try {
        $exit = $command->run($input, $output);

        return [$exit, $output->fetch()];
    } finally {
        fclose($stream);
    }
}

function reader_socket(bool $available = true): WebSocketClient
{
    return new class($available) implements WebSocketClient
    {
        public ?WebSocketEndpoint $endpoint = null;

        public array $sent = [];

        public bool $closed = false;

        public function __construct(private bool $available) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            if (! $this->available) {
                throw new RuntimeException('Upstream leaked new.fixture-reader-token');
            }
            $this->endpoint = $endpoint;
        }

        public function send(array $message): void
        {
            $this->sent[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $last = array_last($this->sent);

            return [['id' => $last['id'], 'type' => $last['type'], 'payload' => ['data' => ['results' => []], 'total' => 0]]];
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function isConnected(): bool
        {
            return true;
        }
    };
}

function install_with_socket(WebSocketClient $socket): void
{
    app()->instance(InstallSwampReaderCredentialAction::class, new InstallSwampReaderCredentialAction(
        app(GatewayReleaseLock::class), app(SwampReaderEnvironment::class), $socket,
    ));
}

function reader_assistance_project(): Project
{
    app(TaskExtensionState::class)->enable();

    return Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'git@example.test:orbit.git', 'default_branch' => 'main']);
}

it('verifies the replacement and installs it with an exact private backup without printing credentials', function (): void {
    $socket = reader_socket();
    install_with_socket($socket);
    [$exit, $output] = install_reader_input(json_encode($this->reveal));
    expect($exit)->toBe(0);
    expect($output)->toContain('orbit-gateway-read-new', 'before-swamp-', 'Apply Gateway configuration')
        ->not->toContain($this->reveal['token'], 'old.fixture-reader-token');
    $installed = file_get_contents($this->envPath);
    expect($installed)->toContain("APP_NAME=Orbit\nUNCHANGED='value with spaces'", 'ORBIT_SWAMP_ADDRESS=192.0.2.17', 'ORBIT_SWAMP_CA_PATH=/test/ca.pem',
        'ORBIT_SWAMP_READ_TOKEN=new.fixture-reader-token', 'ORBIT_SWAMP_READ_TOKEN_NAME=orbit-gateway-read-new', 'ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT=2026-11-26T12:00:00.000000Z')
        ->not->toContain('old.fixture-reader-token');
    $backups = glob($this->envPath.'.before-swamp-*');
    expect($backups)->toHaveCount(1);
    expect(file_get_contents($backups[0]))->toBe($this->original);
    expect(fileperms($backups[0]) & 0777)->toBe(0600);
    expect(fileperms($this->envPath) & 0777)->toBe(0600);
    expect(glob(dirname($this->envPath).'/.gateway.env.swamp-*'))->toBe([]);
    expect($socket->endpoint->bearerToken)->toBe('new.fixture-reader-token');
    expect($socket->endpoint->address)->toBe('192.0.2.17');
    expect($socket->endpoint->serverName)->toBe('swamp.orbit');
    expect($socket->endpoint->caPath)->toBe('/test/ca.pem');
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run.search']);
    expect($socket->closed)->toBeTrue();
});

it('leaves the env unchanged when Swamp is unreachable and never renders upstream errors', function (): void {
    install_with_socket(reader_socket(false));
    [$exit, $output] = install_reader_input(json_encode($this->reveal));
    expect($exit)->toBe(1);
    expect($output)->not->toContain('new.fixture-reader-token', 'Upstream');
    expect(file_get_contents($this->envPath))->toBe($this->original);
    expect(glob($this->envPath.'.before-swamp-*'))->toBe([]);
});

it('rejects invalid reveal input before any network or file change', function (string $case): void {
    $socket = reader_socket();
    install_with_socket($socket);
    $reveal = $this->reveal;
    $expiry = '2026-11-26T12:00:00Z';
    match ($case) {
        'expired' => $reveal['expired'] = true,
        'missing status' => $reveal = array_diff_key($reveal, ['expired' => true]),
        'worker' => $reveal['name'] = 'worker-key',
        'injection' => $reveal['token'] = "token-value\nAPP_KEY=changed",
        'seven days' => $expiry = '2026-11-03T12:00:00Z',
        'bad date' => $expiry = '2026-11-31T12:00:00Z',
        'missing expiry' => $expiry = '',
        default => null,
    };
    $json = match ($case) {
        'oversized' => str_repeat('x', SwampReaderCredential::InputLimit + 1),
        'malformed' => '{new.fixture-reader-token',
        default => json_encode($reveal),
    };
    [$exit, $output] = install_reader_input($json, $expiry);
    expect($exit)->toBe(1);
    expect($output)->not->toContain('new.fixture-reader-token');
    expect($socket->endpoint)->toBeNull();
    expect(file_get_contents($this->envPath))->toBe($this->original);
    expect(glob($this->envPath.'.before-swamp-*'))->toBe([]);
})->with(['expired', 'missing status', 'worker', 'injection', 'seven days', 'bad date', 'missing expiry', 'oversized', 'malformed']);

it('refuses unsafe env state without editing the file', function (string $case): void {
    install_with_socket(reader_socket());
    match ($case) {
        'duplicate' => file_put_contents($this->envPath, "export ORBIT_SWAMP_READ_TOKEN=second.fixture-token\n", FILE_APPEND),
        'multiline' => file_put_contents($this->envPath, "ORBIT_SWAMP_READ_TOKEN=\"first\nsecond\"\n"),
        'mode' => chmod($this->envPath, 0644),
        'malformed' => file_put_contents($this->envPath, "APP_KEY=\"unterminated\n"),
        'same name' => $this->reveal['name'] = 'orbit-gateway-read-old',
        'same token' => $this->reveal['token'] = 'old.fixture-reader-token',
    };
    $before = file_get_contents($this->envPath);
    [$exit] = install_reader_input(json_encode($this->reveal));
    expect($exit)->toBe(1);
    expect(file_get_contents($this->envPath))->toBe($before);
    expect(glob($this->envPath.'.before-swamp-*'))->toBe([]);
})->with(['duplicate', 'multiline', 'mode', 'malformed', 'same name', 'same token']);

it('refuses a symlinked env and concurrent release without changing the original', function (): void {
    install_with_socket(reader_socket());
    rename($this->envPath, $this->envPath.'.original');
    symlink($this->envPath.'.original', $this->envPath);
    expect(install_reader_input(json_encode($this->reveal))[0])->toBe(1);
    expect(file_get_contents($this->envPath.'.original'))->toBe($this->original);
    unlink($this->envPath);
    rename($this->envPath.'.original', $this->envPath);
    app(GatewayReleaseLock::class)->run(function (): void {
        expect(install_reader_input(json_encode($this->reveal))[0])->toBe(1);
    });
    expect(file_get_contents($this->envPath))->toBe($this->original);
});

it('requests one operator task at the seven day boundary and reuses it across checks', function (): void {
    $project = reader_assistance_project();
    $this->travelTo('2026-11-19 11:59:59');
    expect(app(CheckSwampReaderCredentialAction::class)->execute())->toBeNull();
    $this->travel(1)->seconds();
    $id = app(CheckSwampReaderCredentialAction::class)->execute();
    $this->travel(1)->hours();
    expect(app(CheckSwampReaderCredentialAction::class)->execute())->toBe($id);
    $group = Task::topLevel()->findOrFail($id);
    expect($group->project_id)->toBe($project->id);
    expect($group->status)->toBe(TaskGroupStatus::Backlog);
    expect($group->assistance_kind)->toBe(AssistanceKind::Direction);
    expect($group->assistance_requested)->toBeTrue();
    expect($group->brief)->toContain('2026-11-26', 'gateway:swamp-reader:install')->not->toContain('old.fixture-reader-token');
    expect($group->tasks()->count())->toBe(0);
    expect(Setting::query()->where('key', 'like', 'swamp.reader_assistance.%')->count())->toBe(1);
    expect(Setting::query()->where('key', 'like', 'swamp.reader_assistance.%')->first()->value)->toBe((string) $id);
});

it('requests assistance for unknown or expired metadata', function (string $expiry): void {
    reader_assistance_project();
    file_put_contents($this->envPath, preg_replace('/ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT=[^\n]*/', 'ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT='.$expiry, $this->original));
    $id = app(CheckSwampReaderCredentialAction::class)->execute();
    expect(Task::topLevel()->findOrFail($id)->assistance_requested)->toBeTrue();
})->with(['', 'invalid', '2026-10-26T12:00:00Z']);

it('fails visibly if a due request cannot reach Orbit assistance', function (bool $tasks): void {
    if ($tasks) {
        app(TaskExtensionState::class)->enable();
    }
    $this->travelTo('2026-11-25 12:00:00');
    $this->artisan('gateway:swamp-reader:check')->expectsOutputToContain('Cannot check')->assertFailed();
    expect(Task::topLevel()->count())->toBe(0);
})->with([false, true]);

it('skips an unconfigured reader and uses the env expiry instead of stale configuration', function (): void {
    config(['swamp.read_token' => 'stale.fixture-token']);
    file_put_contents($this->envPath, "APP_NAME=Orbit\n");
    $this->artisan('gateway:swamp-reader:check')->assertSuccessful();
    file_put_contents($this->envPath, $this->original);
    expect(app(CheckSwampReaderCredentialAction::class)->execute())->toBeNull();
});

it('does not publish a replacement when a private backup cannot be created', function (): void {
    install_with_socket(reader_socket());
    chmod(dirname($this->envPath), 0500);
    try {
        expect(install_reader_input(json_encode($this->reveal))[0])->toBe(1);
        expect(file_get_contents($this->envPath))->toBe($this->original);
    } finally {
        chmod(dirname($this->envPath), 0700);
    }
});

it('requests assistance when the existing token has no recorded name', function (): void {
    reader_assistance_project();
    file_put_contents($this->envPath, str_replace('ORBIT_SWAMP_READ_TOKEN_NAME=orbit-gateway-read-old', 'ORBIT_SWAMP_READ_TOKEN_NAME=', $this->original));
    expect(app(CheckSwampReaderCredentialAction::class)->execute())->toBeInt();
});

it('registers both internal commands and checks expiry hourly', function (): void {
    $all = Artisan::all();
    expect($all['gateway:swamp-reader:install'])->toBeInstanceOf(InstallSwampReaderCredentialCommand::class);
    expect($all['gateway:swamp-reader:check'])->toBeInstanceOf(CheckSwampReaderCredentialCommand::class);
    $events = app(Schedule::class)->events();
    $event = array_find($events, fn ($event) => $event->description === 'gateway:swamp-reader:check' || str_contains($event->command ?? '', 'gateway:swamp-reader:check'));
    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 * * * *');
    config(['swamp.read_token' => '']);
    expect($event->filtersPass(app()))->toBeFalse();
    config(['swamp.read_token' => 'configured.fixture-token']);
    expect($event->filtersPass(app()))->toBeTrue();
});
