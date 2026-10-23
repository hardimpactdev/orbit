<?php

declare(strict_types=1);

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Instances\RemoteInstanceSqliteCloner;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\LocalAgentBinary;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-sqlite-cloner-'.bin2hex(random_bytes(6));
    mkdir("{$this->root}/default/database", 0755, true);
    mkdir("{$this->root}/feature", 0755, true);
    $database = new PDO("sqlite:{$this->root}/default/database/database.sqlite");
    $database->exec('CREATE TABLE users (name TEXT); INSERT INTO users VALUES (\'ada\');');
    $database = null;
    chmod("{$this->root}/default/database/database.sqlite", 0640);

    $this->project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
    ]);
    $this->sourceNode = sqlite_cloner_node('source', '10.44.0.11');
    $this->default = sqlite_cloner_instance($this->project, $this->sourceNode, 'default', "{$this->root}/default");
});

afterEach(function (): void {
    (new NativeProcessRunner)->run(new ProcessInvocation(['rm', '-rf', '--', $this->root]));
});

function sqlite_cloner_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
        'user' => (string) posix_getpwuid(posix_geteuid())['name'],
    ]);
}

function sqlite_cloner_instance(Project $project, Node $node, string $name, string $checkout): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => $checkout,
    ]);
}

function sqlite_cloner(SqliteClonerRecordingAgents $agents): RemoteInstanceSqliteCloner
{
    return new RemoteInstanceSqliteCloner(
        $agents,
        new class implements SqliteSnapshotTransfer
        {
            public function transfer(
                SqliteSeedPlacement $source,
                string $sourcePath,
                SqliteSeedPlacement $target,
                string $targetPath,
                int $expectedBytes,
                string $expectedDigest,
            ): void {
                if (filesize($sourcePath) !== $expectedBytes || hash_file('sha256', $sourcePath) !== $expectedDigest) {
                    throw new RuntimeException('The snapshot changed in transit.');
                }

                copy($sourcePath, $targetPath);
            }
        },
    );
}

/** Whether `cp` can clone a file inside the directory, the same block cloning the program uses. */
function sqlite_cloner_can_reflink(string $directory): bool
{
    file_put_contents("{$directory}/reflink-probe", 'probe');
    $result = (new NativeProcessRunner)->run(new ProcessInvocation(['cp', '--reflink=always', "{$directory}/reflink-probe", "{$directory}/reflink-probe-copy"]));
    @unlink("{$directory}/reflink-probe");
    @unlink("{$directory}/reflink-probe-copy");

    return $result->succeeded();
}

function sqlite_cloner_rows(string $path): array
{
    return (new PDO("sqlite:{$path}"))->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);
}

describe('RemoteInstanceSqliteCloner', function (): void {
    it('copies a consistent snapshot into the same relative path on the same Node', function (): void {
        $agents = new SqliteClonerRecordingAgents("{$this->root}/agent");
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");

        sqlite_cloner($agents)->copy($this->default, "{$this->root}/default/database/database.sqlite", $target, "{$this->root}/feature/database/database.sqlite");

        expect(sqlite_cloner_rows("{$this->root}/feature/database/database.sqlite"))->toBe(['ada'])
            ->and(fileperms("{$this->root}/feature/database/database.sqlite") & 0777)->toBe(0640)
            ->and(glob("{$this->root}/feature/database/.orbit-sqlite-*") ?: [])->toBe([])
            ->and(array_column($agents->commands, 'operation'))->toBe(['local'])
            ->and($agents->hosts)->toBe(['10.44.0.11']);
    });

    it('copies writes that are still in the WAL, by reflink where the filesystem can clone and by snapshot otherwise', function (): void {
        $source = "{$this->root}/default/database/database.sqlite";
        $writer = new PDO("sqlite:{$source}");
        $writer->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0;');
        $writer->exec("INSERT INTO users VALUES ('grace')");
        $agents = new SqliteClonerRecordingAgents("{$this->root}/agent");
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");

        expect(filesize("{$source}-wal"))->toBeGreaterThan(0);

        sqlite_cloner($agents)->copy($this->default, $source, $target, "{$this->root}/feature/database/database.sqlite");

        expect(sqlite_cloner_rows("{$this->root}/feature/database/database.sqlite"))->toBe(['ada', 'grace'])
            ->and($agents->outputs[0]['copy'])->toBe(sqlite_cloner_can_reflink("{$this->root}/feature") ? 'reflink' : 'snapshot')
            ->and(glob("{$this->root}/feature/database/.orbit-sqlite-*") ?: [])->toBe([]);

        $writer = null;
    });

    it('copies a snapshot instead of waiting when a writer holds the lock', function (): void {
        $source = "{$this->root}/default/database/database.sqlite";
        $writer = new PDO("sqlite:{$source}");
        $writer->exec('PRAGMA journal_mode=WAL;');
        $writer->exec('BEGIN IMMEDIATE');
        $writer->exec("INSERT INTO users VALUES ('uncommitted')");
        $agents = new SqliteClonerRecordingAgents("{$this->root}/agent");
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");
        $started = hrtime(true);

        sqlite_cloner($agents)->copy($this->default, $source, $target, "{$this->root}/feature/database/database.sqlite");

        expect(sqlite_cloner_rows("{$this->root}/feature/database/database.sqlite"))->toBe(['ada'])
            ->and($agents->outputs[0]['copy'])->toBe('snapshot')
            ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(10.0);

        $writer->exec('ROLLBACK');
        $writer = null;
    });

    it('moves the snapshot through the Gateway to another Node and removes the source snapshot', function (): void {
        $agents = new SqliteClonerRecordingAgents("{$this->root}/agent");
        $target = sqlite_cloner_instance($this->project, sqlite_cloner_node('target', '10.44.0.12'), 'feature', "{$this->root}/feature");

        sqlite_cloner($agents)->copy($this->default, "{$this->root}/default/database/database.sqlite", $target, "{$this->root}/feature/database/database.sqlite");

        $exportDirectory = $agents->commands[0]['directory'];

        expect(sqlite_cloner_rows("{$this->root}/feature/database/database.sqlite"))->toBe(['ada'])
            ->and(array_column($agents->commands, 'operation'))->toBe(['export', 'prepare', 'install', 'cleanup'])
            ->and($agents->hosts)->toBe(['10.44.0.11', '10.44.0.12', '10.44.0.12', '10.44.0.11'])
            ->and(is_dir($exportDirectory))->toBeFalse()
            ->and(glob("{$this->root}/feature/database/.orbit-sqlite-*") ?: [])->toBe([]);
    });

    it('refuses a source outside the checkout or through a symlink', function (string $source): void {
        symlink("{$this->root}/default/database", "{$this->root}/default/linked");
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");
        $source = str_replace('ROOT', $this->root, $source);

        expect(fn () => sqlite_cloner(new SqliteClonerRecordingAgents("{$this->root}/agent"))->copy($this->default, $source, $target, "{$this->root}/feature/database.sqlite"))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.database_clone_failed'));

        expect(file_exists("{$this->root}/feature/database.sqlite"))->toBeFalse();
    })->with([
        'outside' => ['ROOT/feature/../default/database/database.sqlite'],
        'symlink' => ['ROOT/default/linked/database.sqlite'],
    ]);

    it('removes a copied file and treats a missing checkout as removed', function (): void {
        $target = sqlite_cloner_instance($this->project, $this->sourceNode, 'feature', "{$this->root}/feature");
        touch("{$this->root}/feature/database.sqlite");
        $cloner = sqlite_cloner(new SqliteClonerRecordingAgents("{$this->root}/agent"));

        $cloner->remove($target, "{$this->root}/feature/database.sqlite");
        $cloner->remove(sqlite_cloner_instance($this->project, $this->sourceNode, 'gone', "{$this->root}/gone"), "{$this->root}/gone/database.sqlite");

        expect(file_exists("{$this->root}/feature/database.sqlite"))->toBeFalse();
    });
});

/** The built agent, run locally, with each call's host, input, and answer recorded. */
final class SqliteClonerRecordingAgents implements AgentCommandRunner
{
    /** @var list<array<string, mixed>> */
    public array $commands = [];

    /** @var list<string> */
    public array $hosts = [];

    /** @var list<array<string, mixed>> */
    public array $outputs = [];

    private AgentCommandRunner $agents;

    public function __construct(string $sandbox)
    {
        $this->agents = LocalAgentBinary::localRunner($sandbox);
    }

    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        $this->hosts[] = (string) $node->wireguard_ip;
        $this->commands[] = $input;

        return $this->outputs[] = $this->agents->run($node, $command, $input, $deadlineSeconds);
    }

    public function install(Node $node): bool
    {
        return $this->agents->install($node);
    }
}
