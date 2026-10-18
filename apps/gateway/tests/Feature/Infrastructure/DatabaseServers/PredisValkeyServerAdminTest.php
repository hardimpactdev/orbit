<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseServers\ConvergeValkeyServerAction;
use App\Actions\DatabaseServers\WriteValkeyAclFileAction;
use App\Actions\Doctor\DatabaseServerDoctorProbe;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Symfony\Component\Process\Process as LocalProcess;

/**
 * Runs the Valkey Database server code against a real Valkey in Docker. Each test starts a disposable server as Orbit
 * does: the ACL file comes from the records through the real `database-servers.write-valkey-acl` program, and the
 * container runs the image and command of the server's Process specification. The server listens on a random
 * loopback port. The tests run when ORBIT_TEST_DOCKER=1, as in CI's Gateway privileged job, and need Docker and
 * passwordless sudo.
 */
pest()->group('privileged');

/** Runs remote programs on this host, as SshProgramRunner runs them on a Node. */
final readonly class ValkeyDockerTestProgramRunner implements RemoteProgramRunner
{
    public function run(
        Node $node,
        RemoteProgram $program,
        array $input = [],
        #[SensitiveParameter] ?GitReadEnvironment $gitRead = null,
        ?float $timeoutSeconds = null,
        ?Closure $cancelled = null,
    ): ProgramResult {
        $command = SshProgramRunner::pythonCommand($program, app(RemoteProgramCatalog::class)->bootstrap(), $input);
        $process = new LocalProcess($command->arguments);
        $process->setInput($command->input)->setTimeout($command->timeout)->run();

        return SshProgramRunner::pythonResult(
            $program,
            new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 0, false),
        );
    }
}

/** Restarts the server's container and reads its state; a repair needs nothing else from the Process runtime. */
final readonly class ValkeyDockerTestRuntime implements ProcessRuntimeManager
{
    public function __construct(private string $container) {}

    public function assertCanStart(Process $process): void {}

    public function converge(Process $process): void
    {
        throw new LogicException('Not used.');
    }

    public function start(Process $process, bool $explicit = false): void
    {
        throw new LogicException('Not used.');
    }

    public function stop(Process $process): void
    {
        throw new LogicException('Not used.');
    }

    public function restart(Process $process): void
    {
        valkey_docker(['restart', $this->container]);
    }

    public function remove(Process $process): void
    {
        throw new LogicException('Not used.');
    }

    public function status(Process $process): string
    {
        return valkey_docker(['inspect', '--format', '{{.State.Status}}', $this->container]);
    }

    public function logs(Process $process, int $lines): string
    {
        throw new LogicException('Not used.');
    }
}

/** @param list<string> $arguments */
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
    return app(CreateServerDatabaseAction::class)->execute(
        new CreateServerDatabaseData(slug: $slug, server: $server->slug, instanceId: null, patterns: $patterns, allow: $allow),
        'test',
    );
}

/** A free loopback port. The container keeps it across restarts, as a server keeps its published port. */
function valkey_docker_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No free port.');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':') ?: ':0', 1);
    fclose($socket);

    return $port;
}

/** Whether the container stops; Valkey without its ACL file exits at once. */
function valkey_docker_stopped(string $container): bool
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (valkey_docker(['inspect', '--format', '{{.State.Running}}', $container]) === 'false') {
            return true;
        }

        usleep(100_000);
    }

    return false;
}

beforeEach(function (): void {
    if (getenv('ORBIT_TEST_DOCKER') !== '1') {
        $this->markTestSkipped('Set ORBIT_TEST_DOCKER=1 to run against a real Valkey in Docker.');
    }

    // Valkey starts and restarts in real time, so readiness waits really sleep.
    Sleep::fake(false);
    $name = 'orbit-test-valkey-'.bin2hex(random_bytes(6));
    $this->container = $name;
    $this->volume = DatabaseServerProcess::volume($name);
    app()->instance(RemoteProgramRunner::class, new ValkeyDockerTestProgramRunner);
    app()->instance(ProcessRuntimeManager::class, new ValkeyDockerTestRuntime($name));

    $this->node = Node::query()->create([
        'name' => 'valkey-docker', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80', 'user' => 'orbit', 'wireguard_ip' => '127.0.0.1',
    ]);
    $this->server = DatabaseServer::query()->create([
        'slug' => $name, 'driver' => DatabaseDriver::Redis, 'node_id' => $this->node->id, 'tag' => '8.1', 'port' => valkey_docker_free_port(),
        'root_password' => Str::random(48), 'status' => LifecycleStatus::Active,
    ]);
    $specification = DatabaseServerProcess::valkeyData($this->server, $this->node, $name, $this->volume);
    $process = Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => $name, 'runtime' => ProcessRuntime::Docker,
        'working_directory' => $specification->workingDirectory,
        'runtime_config' => ['image' => $specification->image, 'command' => $specification->command, 'volumes' => $specification->volumes],
        'restart_policy' => 'never', 'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Active,
    ]);
    $this->server->update(['process_id' => $process->id]);

    app(WriteValkeyAclFileAction::class)->execute($this->server, $this->volume);
    valkey_docker([
        'run', '--detach', '--name', $name, '--publish', DatabaseServerProcess::publishedPort($this->node, $this->server->port, DatabaseServerProcess::VALKEY_CONTAINER_PORT),
        '--mount', "type=volume,source={$this->volume},target=".DatabaseServerProcess::VALKEY_DATA_DIRECTORY,
        '--workdir', (string) $specification->workingDirectory, (string) $specification->image, ...$specification->command,
    ]);

    $this->admin = app(ValkeyServerAdmin::class);
    $this->password = $this->server->root_password;
    expect($this->admin->waitUntilReady($this->server, 30))->toBeTrue();
});

afterEach(function (): void {
    if (isset($this->container)) {
        new LocalProcess(['docker', 'rm', '--force', '--volumes', $this->container])->run();
        new LocalProcess(['docker', 'volume', 'rm', '--force', $this->volume])->run();
    }
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

    it('creates a user in Orbit\'s frame, saves it to the ACL file, and deletes it', function (): void {
        $rules = $this->admin->setUser(
            $this->server,
            'orbit-test-queue',
            'queue-secret',
            ['orbit-gateway:*', 'orbit-gateway-horizon:*'],
            ['@connection', '@list', '@write', '@slow', 'eval'],
        );

        expect($rules)->toBe('on sanitize-payload ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +@list +@write +@slow +eval -@admin -@dangerous -function -script')
            ->and($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeTrue()
            ->and($this->admin->aclUsers($this->server)['orbit-test-queue']->rules)->toBe($rules)
            ->and($this->admin->aclUsers($this->server)['orbit-test-queue']->hasOnlyPassword('queue-secret'))->toBeTrue();

        $default = valkey_docker_client($this->server, 'default', $this->password);

        // ACL LOAD reads the file again, so the user survives only when ACL SAVE wrote it.
        expect((string) $default->executeRaw(['ACL', 'LOAD']))->toBe('OK')
            ->and($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeTrue();

        $queue = valkey_docker_client($this->server, 'orbit-test-queue', 'queue-secret');

        expect($queue->executeRaw(['RPUSH', 'orbit-gateway:orbit-test-jobs', 'job']))->toBe(1)
            ->and((string) $queue->executeRaw(['RPUSH', 'other:orbit-test-jobs', 'job']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['KEYS', '*']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['FLUSHALL']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['FUNCTION', 'FLUSH']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['SCRIPT', 'FLUSH']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['CONFIG', 'GET', 'aclfile']))->toStartWith('NOPERM')
            ->and((string) $queue->executeRaw(['ACL', 'LIST']))->toStartWith('NOPERM');

        $this->admin->deleteUser($this->server, 'orbit-test-queue');
        $this->admin->deleteUser($this->server, 'orbit-test-queue');

        expect($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeFalse();
    });

    it('reports the recorded users as healthy through Doctor, with the exact rules that Valkey lists', function (): void {
        valkey_docker_user($this->server, 'gateway-queue', ['orbit-gateway:*', 'orbit-gateway-horizon:*'],
            ['@connection', '@transaction', '@keyspace', '@string', '@list', '@hash', '@set', '@sortedset', 'eval', 'time']);
        valkey_docker_user($this->server, 'collector', ['orbit:proxycli:*'], ['@connection', '@string', '@keyspace', 'eval']);
        $context = new DoctorNodeContext($this->node, new NodeInspectionData(true, 'linux', 'x86_64', true));

        expect(ValkeyAcl::changes($this->server->refresh(), $this->admin->aclUsers($this->server)))->toBe(['set' => [], 'delete' => []])
            ->and(app(DatabaseServerDoctorProbe::class)->inspect($context)->issues)->toBe([]);

        valkey_docker_client($this->server, 'default', $this->password)->executeRaw(['ACL', 'SETUSER', 'collector', '+get']);

        expect(array_map(static fn ($issue): string => $issue->code, app(DatabaseServerDoctorProbe::class)->inspect($context)->issues))
            ->toBe(['database_server.acl_mismatch']);
    });

    it('repairs live drift with the ACL commands and keeps the keys', function (): void {
        $connection = valkey_docker_user($this->server, 'gateway-queue', ['orbit-gateway:*'], ['@connection', '@list']);
        $default = valkey_docker_client($this->server, 'default', $this->password);
        $default->executeRaw(['SET', 'orbit-gateway:kept', 'job']);
        $default->executeRaw(['ACL', 'DELUSER', 'gateway-queue']);
        $default->executeRaw(['ACL', 'SETUSER', 'manual', 'on', 'nopass', '~*', '+@all']);
        $default->executeRaw(['ACL', 'SETUSER', 'default', '>second-admin-password', '-flushall']);
        $default->disconnect();

        app(ConvergeValkeyServerAction::class)->execute($this->server);
        app(ConvergeValkeyServerAction::class)->execute($this->server);

        $default = valkey_docker_client($this->server, 'default', $this->password);
        expect((string) $default->executeRaw(['ACL', 'LOAD']))->toBe('OK')
            ->and(ValkeyAcl::changes($this->server->refresh(), $this->admin->aclUsers($this->server)))->toBe(['set' => [], 'delete' => []])
            ->and(array_keys($this->admin->aclUsers($this->server)))->toBe(['default', 'gateway-queue'])
            ->and(valkey_docker_client($this->server, 'gateway-queue', (string) $connection->password)->executeRaw(['LLEN', 'orbit-gateway:jobs']))->toBe(0)
            ->and($default->executeRaw(['GET', 'orbit-gateway:kept']))->toBe('job');
    });

    it('repairs default when it can log in but cannot inspect or restore the server', function (string $deny): void {
        $connection = valkey_docker_user($this->server, 'gateway-queue', ['orbit-gateway:*'], ['@connection', '@list']);
        $default = valkey_docker_client($this->server, 'default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', $deny]);
        $default->executeRaw(['ACL', 'SAVE']);
        $default->disconnect();
        expect($this->admin->acceptsAdminLogin($this->server))->toBeTrue();

        app(ConvergeValkeyServerAction::class)->execute($this->server);

        expect(ValkeyAcl::changes($this->server->refresh(), $this->admin->aclUsers($this->server)))->toBe(['set' => [], 'delete' => []])
            ->and($this->admin->acceptsAnonymous($this->server))->toBeFalse()
            ->and(valkey_docker_client($this->server, 'gateway-queue', (string) $connection->password)->executeRaw(['LLEN', 'orbit-gateway:jobs']))->toBe(0);
    })->with(['CONFIG GET denied' => '-config|get', 'ACL LIST denied' => '-acl|list', 'ACL SETUSER denied' => '-acl|setuser']);

    it('writes a lost ACL file again from the records and starts Valkey from it', function (): void {
        $connection = valkey_docker_user($this->server, 'gateway-queue', ['orbit-gateway:*'], ['@connection', '@list', '@string']);
        valkey_docker_client($this->server, 'gateway-queue', (string) $connection->password)->executeRaw(['SET', 'orbit-gateway:kept', 'job']);
        valkey_docker(['exec', $this->container, 'rm', DatabaseServerProcess::VALKEY_ACL_FILE]);
        valkey_docker(['restart', $this->container]);

        expect(valkey_docker_stopped($this->container))->toBeTrue()
            ->and($this->admin->acceptsAdminLogin($this->server))->toBeFalse();

        app(ConvergeValkeyServerAction::class)->execute($this->server);

        $queue = valkey_docker_client($this->server, 'gateway-queue', (string) $connection->password);
        expect($queue->executeRaw(['GET', 'orbit-gateway:kept']))->toBe('job')
            ->and($this->admin->acceptsAnonymous($this->server))->toBeFalse()
            ->and(ValkeyAcl::changes($this->server->refresh(), $this->admin->aclUsers($this->server)))->toBe(['set' => [], 'delete' => []]);
    });

    it('refuses default clients then closes an open server durably without deleting keys or other users', function (): void {
        $default = valkey_docker_client($this->server, 'default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', 'nopass']);
        $default->executeRaw(['ACL', 'SAVE']);
        $default->executeRaw(['SET', 'orbit-test-preserved', 'job']);

        expect(fn () => $this->admin->closeDefaultUser($this->server))->toThrow(ResourceOperationException::class, 'clients still use default');
        expect($this->admin->acceptsAnonymous($this->server))->toBeTrue();
        $default->disconnect();
        $this->admin->closeDefaultUser($this->server);
        expect($this->admin->acceptsAnonymous($this->server))->toBeFalse();
        $this->admin->closeDefaultUser($this->server);
        $default = valkey_docker_client($this->server, 'default', $this->password);
        expect((string) $default->executeRaw(['ACL', 'LOAD']))->toBe('OK')
            ->and($default->executeRaw(['GET', 'orbit-test-preserved']))->toBe('job')
            ->and($this->admin->acceptsAnonymous($this->server))->toBeFalse();
    });

    it('revokes a session accepted before an interrupted close on retry', function (): void {
        $default = valkey_docker_client($this->server, 'default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', 'nopass']);
        $anonymous = new Client(['host' => '127.0.0.1', 'port' => $this->server->port, 'timeout' => 5]);
        expect((string) $anonymous->executeRaw(['PING']))->toBe('PONG');
        $default->executeRaw(['ACL', 'SETUSER', 'default', 'resetpass', '#'.hash('sha256', $this->password)]);
        $default->executeRaw(['ACL', 'SAVE']);
        $default->disconnect();
        // This already-authenticated session survives the password change, including a lost close reply.
        expect($anonymous->executeRaw(['ACL', 'LIST']))->toBeArray();

        $this->admin->closeDefaultUser($this->server);

        try {
            $reply = $anonymous->executeRaw(['ACL', 'LIST']);
            expect((string) $reply)->toStartWith('NOAUTH');
        } catch (ConnectionException) {
            $anonymous->disconnect();
            expect((string) $anonymous->executeRaw(['ACL', 'LIST']))->toStartWith('NOAUTH');
        }
        expect($this->admin->acceptsAnonymous($this->server))->toBeFalse();
    });

    it('reads the effective persistence and database settings before adoption', function (): void {
        expect($this->admin->adoptionConfiguration($this->server))->toMatchArray([
            'dir' => '/data', 'appendonly' => 'yes', 'appenddirname' => 'appendonlydir',
            'appendfilename' => 'appendonly.aof', 'dbfilename' => 'dump.rdb', 'databases' => '16',
        ]);
    });

    it('restores an open default when ACL SAVE fails and allows a safe retry', function (): void {
        $default = valkey_docker_client($this->server, 'default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', 'nopass']);
        $default->executeRaw(['ACL', 'SAVE']);
        // Removing SAVE permission causes a real command failure without filesystem tricks.
        $default->executeRaw(['ACL', 'SETUSER', 'default', '-acl|save']);
        $default->disconnect();

        expect(fn () => $this->admin->closeDefaultUser($this->server))->toThrow(ResourceOperationException::class);
        expect($this->admin->acceptsAnonymous($this->server))->toBeTrue();

        $default->executeRaw(['ACL', 'SETUSER', 'default', '+@all']);
        $default->disconnect();
        $this->admin->closeDefaultUser($this->server);

        expect($this->admin->acceptsAnonymous($this->server))->toBeFalse();
    });

    it('removes a newly created user when saving it fails', function (): void {
        $default = valkey_docker_client($this->server, 'default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', '-acl|save']);

        expect(fn () => $this->admin->setUser($this->server, 'orbit-test-queue', 'queue-secret', ['queue:*'], ['@list']))->toThrow(ResourceOperationException::class);
        expect($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeFalse();
    });

    it('restores a deleted user when saving fails and persists its deletion on retry', function (): void {
        $this->admin->setUser($this->server, 'orbit-test-queue', 'queue-secret', ['queue:*'], ['@list']);
        $default = valkey_docker_client($this->server, 'default', $this->password);
        $default->executeRaw(['ACL', 'SETUSER', 'default', '-acl|save']);

        expect(fn () => $this->admin->deleteUser($this->server, 'orbit-test-queue'))->toThrow(ResourceOperationException::class);
        expect($this->admin->aclUsers($this->server)['orbit-test-queue']->hasOnlyPassword('queue-secret'))->toBeTrue();

        $default->executeRaw(['ACL', 'SETUSER', 'default', '+@all']);
        $this->admin->deleteUser($this->server, 'orbit-test-queue');
        $default->executeRaw(['ACL', 'LOAD']);

        expect($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeFalse();
    });

    it('reports the live database count and accepts only indexes below it', function (): void {
        expect($this->admin->databaseCount($this->server))->toBe(16);
        $default = valkey_docker_client($this->server, 'default', $this->password);
        expect((string) $default->executeRaw(['SELECT', '15']))->toBe('OK')
            ->and((string) $default->executeRaw(['SELECT', '16']))->toStartWith('ERR DB index is out of range');
    });

    it('runs the documented collector ACL through the Predis handshake and cache commands', function (): void {
        $this->admin->setUser($this->server, 'orbit-test-queue', 'queue-secret', ['orbit:proxycli:*'], ['@connection', '@string', '@keyspace', 'eval']);
        $client = valkey_docker_client($this->server, 'orbit-test-queue', 'queue-secret');

        expect((string) $client->executeRaw(['SET', 'orbit:proxycli:test', 'snapshot', 'EX', '60']))->toBe('OK')
            ->and($client->executeRaw(['GET', 'orbit:proxycli:test']))->toBe('snapshot')
            ->and($client->executeRaw(['EVAL', "return redis.call('GET', KEYS[1])", '1', 'orbit:proxycli:test']))->toBe('snapshot')
            ->and((string) $client->executeRaw(['GET', 'other:test']))->toStartWith('NOPERM');
    });

    it('returns a refused rule as database.acl_rules_invalid with the message of Valkey and no hash', function (): void {
        try {
            $this->admin->setUser($this->server, 'orbit-test-queue', 'queue-secret', ['orbit-gateway:*'], ['nosuchcommand']);
            $this->fail('Valkey accepted an unknown command.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('database.acl_rules_invalid')
                ->and($exception->status)->toBe(422)
                ->and($exception->getMessage())->toBe("Valkey refused a key pattern or a rule: ERR Error in ACL SETUSER modifier '+nosuchcommand': Unknown command or category name in ACL.")
                ->and($exception->getMessage())->not->toContain(hash('sha256', 'queue-secret'));
        }

        expect($this->admin->userExists($this->server, 'orbit-test-queue'))->toBeFalse();
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
