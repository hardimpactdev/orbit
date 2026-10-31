<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\Doctor\DatabaseServerDoctorProbe;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Sleep;
use Predis\Client;
use Symfony\Component\Process\Process as LocalProcess;

/**
 * Runs the Valkey Database server code against a real Valkey in Docker. Each test starts a disposable server as Orbit
 * does: its initial ACL is a private copy of an immutable seed checked against its records.
 * These read and Doctor cases seed the private fixture directly; Swamp owns mutation workflow tests.
 * The server listens on a random loopback port. The tests run when ORBIT_TEST_DOCKER=1, as in CI's Gateway privileged job, and need Docker and
 * passwordless sudo. Startup and repair restarts read TCP readiness directly from the attached start process,
 * so no extra inspect/log-follow clients or retry sleep are needed. Containers and volumes are private to each case.
 */
pest()->group('privileged');

/** Only immutable ACL bytes and image metadata are shared; no live server or mutable volume survives preparation. */
final class ValkeyDockerTestSeed
{
    public const string TAG = '8.1';

    public static self $shared;

    /** @param list<string> $entrypoint */
    public function __construct(public readonly string $acl, public readonly array $entrypoint) {}

    public static function prepare(): self
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/valkey/acl-list.json'), true, flags: JSON_THROW_ON_ERROR);
        $acl = array_find($fixture['closed'], static fn (string $line): bool => str_starts_with($line, 'user default '))."\n";
        $image = DatabaseServerProcess::valkeyImage(self::TAG);
        valkey_docker(['pull', $image]);
        $entrypoint = json_decode(valkey_docker(['image', 'inspect', '--format', '{{json .Config.Entrypoint}}', $image]), true, flags: JSON_THROW_ON_ERROR);
        // Start the image once here, so Docker's first container start on a fresh host is not timed in the first test.
        valkey_docker(['run', '--rm', '--network', 'none', '--entrypoint', 'valkey-server', $image, '--version']);

        return new self($acl, $entrypoint);
    }
}

function valkey_docker(array $arguments): string
{
    return trim(new LocalProcess(['docker', ...$arguments])->setTimeout(120)->mustRun()->getOutput());
}

function valkey_docker_client(DatabaseServer $server, string $username, string $password): Client
{
    return new Client([
        'host' => '127.0.0.1',
        'port' => $server->port,
        'username' => $username,
        'password' => $password,
        'timeout' => 5.0,
    ]);
}

/** A recorded ACL user, created as `database:create --server` creates it. */
function valkey_docker_user(DatabaseServer $server, string $slug, array $patterns, array $allow): DatabaseConnection
{
    $prepared = app(CreateServerDatabaseAction::class)->prepare(
        new CreateServerDatabaseData(slug: $slug, server: $server->slug, instanceId: null, patterns: $patterns, allow: $allow),
        'test',
    );
    $connection = DatabaseConnection::query()->findOrFail($prepared->input['connection_id']);
    $user = ValkeyAcl::users($server->refresh())[$slug];
    $client = valkey_docker_client($server, 'default', $server->root_password);
    $client->executeRaw(['ACL', 'SETUSER', $slug, 'reset', ...explode(' ', $user->rules), '#'.hash('sha256', $connection->password)]);
    $client->executeRaw(['ACL', 'SAVE']);
    $client->disconnect();

    return $connection;
}

/** A free loopback port. The container keeps it across restarts, as a server keeps its published port. */
function valkey_docker_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No free port.');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':') ?: ':0', 1);
    fclose($socket);

    return $port;
}

/** An attached run/start exposes only this boot's output, never an old ready message from the log. */
function valkey_docker_ready(LocalProcess $start): void
{
    $start->setTimeout(5)->start();
    if (! $start->waitUntil(fn (): bool => str_contains($start->getOutput(), 'Ready to accept connections tcp'))) {
        throw new RuntimeException('Valkey exited before acknowledging TCP readiness: '.$start->getOutput().$start->getErrorOutput());
    }
}

/** Waits for the actual exit; Valkey without its ACL file cannot start. */
beforeAll(function (): void {
    if (getenv('ORBIT_TEST_DOCKER') === '1') {
        ValkeyDockerTestSeed::$shared = ValkeyDockerTestSeed::prepare();
    }
});

beforeEach(function (): void {
    if (getenv('ORBIT_TEST_DOCKER') !== '1') {
        $this->markTestSkipped('Set ORBIT_TEST_DOCKER=1 to run against a real Valkey in Docker.');
    }

    $name = 'orbit-test-valkey-'.bin2hex(random_bytes(6));
    $this->container = $name;
    $this->volume = DatabaseServerProcess::volume($name);

    $this->node = Node::query()->create([
        'name' => 'valkey-docker', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80', 'user' => 'orbit', 'wireguard_ip' => '127.0.0.1', 'architecture' => 'x86_64',
    ]);
    $this->fixture = json_decode(file_get_contents(base_path('tests/Fixtures/valkey/acl-list.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->server = DatabaseServer::query()->create([
        'slug' => $name, 'driver' => DatabaseDriver::Redis, 'node_id' => $this->node->id, 'tag' => ValkeyDockerTestSeed::TAG, 'port' => valkey_docker_free_port(),
        'root_password' => $this->fixture['admin_password'], 'status' => LifecycleStatus::Active,
    ]);
    $specification = DatabaseServerProcess::valkeyData($this->server, $this->node, $name, $this->volume);
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => $name, 'runtime' => ProcessRuntime::Docker,
        'working_directory' => $specification->workingDirectory,
        'runtime_config' => ['image' => $specification->image, 'command' => $specification->command, 'volumes' => $specification->volumes],
        'restart_policy' => 'never', 'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Active,
    ]);
    $this->server->update(['process_id' => $process->id]);

    $seed = ValkeyDockerTestSeed::$shared;
    expect($seed->acl)->toBe(ValkeyAcl::file($this->server));
    // This marker belongs to the private container, not /data. Restarts must not repair a missing or changed ACL.
    $bootstrap = <<<'SH'
        acl=$1
        shift
        if [ ! -f /tmp/orbit-valkey-fixture-seeded ]; then
            (umask 077; cat > "$acl"; touch /tmp/orbit-valkey-fixture-seeded)
        fi
        exec "$@"
        SH;
    $this->valkeyProcess = new LocalProcess([
        'docker', 'run', '--interactive', '--log-driver', 'none', '--name', $name, '--publish', DatabaseServerProcess::publishedPort($this->node, $this->server->port, DatabaseServerProcess::VALKEY_CONTAINER_PORT),
        '--mount', "type=volume,source={$this->volume},target=".DatabaseServerProcess::VALKEY_DATA_DIRECTORY,
        '--workdir', (string) $specification->workingDirectory, '--entrypoint', 'sh', (string) $specification->image,
        '-eu', '-c', $bootstrap, 'valkey-fixture', DatabaseServerProcess::VALKEY_ACL_FILE, ...$seed->entrypoint, ...$specification->command,
    ]);
    $this->valkeyProcess->setInput($seed->acl);
    valkey_docker_ready($this->valkeyProcess);

    $this->admin = app(ValkeyServerAdmin::class);
    $this->password = $this->server->root_password;
    expect($this->admin->acceptsAdminLogin($this->server))->toBeTrue();
});

afterEach(function (): void {
    if (isset($this->container)) {
        new LocalProcess(['docker', 'rm', '--force', '--volumes', $this->container])->run();
        new LocalProcess(['docker', 'volume', 'rm', '--force', $this->volume])->run();
        if (isset($this->valkeyProcess)) {
            $this->valkeyProcess->wait();
        }
    }

    Sleep::assertNeverSlept();
});

describe('a Valkey Database server in Docker', function (): void {

    it('logs in as default only with the admin password and gives a client without one nothing', function (): void {
        $wrong = $this->server->replicate()->fill(['root_password' => 'wrong-password']);
        $wrong->setRelation('node', $this->node);

        expect($this->admin->acceptsAdminLogin($this->server))->toBeTrue()
            ->and($this->admin->acceptsAdminLogin($wrong))->toBeFalse()
            ->and($this->admin->acceptsAnonymous($this->server))->toBeFalse()
            ->and($this->admin->aclFile($this->server))->toBe('/data/users.acl');
    });

    it('reports default with the admin password and every right, as Doctor expects', function (): void {
        $default = $this->admin->aclUsers($this->server)['default'];

        expect($default->rules)->toBe(ValkeyAclUser::ADMIN_RULES)
            ->and($default->hasOnlyPassword($this->password))->toBeTrue();
    });

    it('reports the recorded users as healthy through Doctor, with the exact rules that Valkey lists', function (): void {
        valkey_docker_user($this->server, 'gateway-queue', ['orbit-gateway:*', 'orbit-gateway-horizon:*'],
            ['@connection', '@transaction', '@keyspace', '@string', '@list', '@hash', '@set', '@sortedset', 'eval', 'time']);
        valkey_docker_user($this->server, 'collector', ['orbit:ai-router:*'], ['@connection', '@string', '@keyspace', 'eval']);
        $context = new DoctorNodeContext($this->node, new NodeInspectionData(true, 'linux', 'x86_64', true));

        expect(ValkeyAcl::changes($this->server->refresh(), $this->admin->aclUsers($this->server)))->toBe(['set' => [], 'delete' => []])
            ->and(app(DatabaseServerDoctorProbe::class)->inspect($context)->issues)->toBe([]);

        valkey_docker_client($this->server, 'default', $this->password)->executeRaw(['ACL', 'SETUSER', 'collector', '+get']);

        expect(array_map(static fn ($issue): string => $issue->code, app(DatabaseServerDoctorProbe::class)->inspect($context)->issues))
            ->toBe(['database_server.acl_mismatch']);
    });

    it('reports the live database count and accepts only indexes below it', function (): void {
        expect($this->admin->databaseCount($this->server))->toBe(16);
        $default = valkey_docker_client($this->server, 'default', $this->password);
        expect((string) $default->executeRaw(['SELECT', '15']))->toBe('OK')
            ->and((string) $default->executeRaw(['SELECT', '16']))->toStartWith('ERR DB index is out of range');
    });

    it('returns a failed connection as database.server_command_failed without output', function (): void {
        $closed = $this->server->replicate()->fill(['port' => 1]);
        $closed->setRelation('node', $this->node);
        $wrong = $this->server->replicate()->fill(['root_password' => 'wrong-password']);
        $wrong->setRelation('node', $this->node);

        expect($this->admin->acceptsAdminLogin($closed))->toBeFalse()
            ->and(fn () => $this->admin->aclUsers($closed))->toThrow(ResourceOperationException::class, "Database server [{$this->server->slug}] could not run an admin command.")
            ->and(fn () => $this->admin->aclUsers($wrong))->toThrow(ResourceOperationException::class, "Database server [{$this->server->slug}] could not run an admin command.");
    });
});
