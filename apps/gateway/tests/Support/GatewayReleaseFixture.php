<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseInventory;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scripted agent answers for the Gateway release commands. The fixture keeps only what the agent reports:
 * the commits it can resolve, the releases it prepared, and the current one. It has no Git, release tree,
 * or file modes; Rust tests prove what each command does on a Node. The temporary directory holds what
 * Gateway PHP itself reads or writes: `ORBIT_HOME` and the files a test places for it.
 */
final class GatewayReleaseFixture
{
    public const string Origin = 'https://github.com/hardimpactdev/orbit.git';

    public readonly string $base;

    public readonly GatewayReleaseLayout $layout;

    public readonly FakeAgentCommandRunner $agents;

    /** @var list<string> the release application paths the builder granted Caddy access to */
    public array $accessGrants = [];

    /** @var list<string> */
    private array $commits = [];

    /** @var array<string, array{sha: string, modified: int, finished: int|null}> keyed by release id */
    private array $releases = [];

    private ?string $current = null;

    private bool $inPlace = false;

    private int $finished = 0;

    private function __construct()
    {
        $this->base = sys_get_temp_dir().'/orbit-release-'.Str::lower(Str::random(10));
        mkdir($this->base, 0700, true);
        $this->agents = $this->scriptedAgents();
        $this->layout = new GatewayReleaseLayout($this->base.'/orbit/apps/gateway', new readonly class($this) implements GatewayReleaseObserver
        {
            public function __construct(private GatewayReleaseFixture $fixture) {}

            public function inspect(GatewayReleaseLayout $layout, ?string $runningApplication = null): GatewayReleaseInventory
            {
                return $this->fixture->releaseCommands()->inspect($layout, $runningApplication);
            }
        });
    }

    /** A fresh fixture; an adopted one already serves a prepared release of its first commit. */
    public static function create(bool $adopted = false): self
    {
        $fixture = new self;
        if ($adopted) {
            $fixture->select($fixture->prepared($fixture->commit('Adopted release')));
        }

        return $fixture;
    }

    /** Adds a commit the agent can resolve and returns its SHA. */
    public function commit(string $message): string
    {
        $sha = sha1(count($this->commits)."\0".$message);
        $this->commits[] = $sha;

        return $sha;
    }

    /** Records a prepared release of a commit, as a finished prepare leaves it, and returns its id. */
    public function prepared(string $sha): string
    {
        $id = GatewayReleaseCommit::id($sha);
        $this->releases[$id] = ['sha' => $sha, 'modified' => time(), 'finished' => ++$this->finished];

        return $id;
    }

    /** Records a release directory without REVISION, as a stopped prepare or a broken repair leaves it. */
    public function incomplete(string $id, int $modified): void
    {
        $this->releases[$id] = ['sha' => $this->releases[$id]['sha'] ?? $id.str_repeat('0', 28), 'modified' => $modified, 'finished' => null];
    }

    /** Points the current path at a release, as a switch or an operator would. */
    public function select(string $id): void
    {
        $this->current = $id;
        $this->inPlace = false;
    }

    /** Makes the current path an in-place checkout, as before adoption. */
    public function inPlace(): void
    {
        $this->current = null;
        $this->inPlace = true;
    }

    public function currentId(): ?string
    {
        return $this->current;
    }

    /**
     * Writes a file into a release directory, for Gateway code that reads the release tree itself:
     * the fleet rollout command or the smoke script.
     */
    public function releaseFile(string $id, string $relative, string $contents, int $mode = 0644): string
    {
        $path = $this->layout->releasePath($id).'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
        chmod($path, $mode);

        return $path;
    }

    public function releaseCommands(?AgentCommandRunner $agents = null): GatewayReleaseCommands
    {
        $node = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->first();
        if (! $node instanceof Node) {
            $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.1']);
            $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        }

        return new GatewayReleaseCommands($agents ?? $this->agents);
    }

    public function builder(?GatewayReleaseWebBuild $web = null): GatewayReleaseBuilder
    {
        return new GatewayReleaseBuilder(
            layout: $this->layout,
            commands: $this->releaseCommands(),
            readAccess: app(RepositoryReadAccess::class),
            web: $web ?? new NoWebBuild,
            checkoutAccess: function (string $application): void {
                $this->accessGrants[] = $application;
            },
            stepLock: $this->base.'/home/gateway-release-step.lock',
        );
    }

    /** Database snapshots for recovery consumers; the snapshot command itself is tested in Rust. */
    public function database(string $home, ?string $connection = null): SqliteGatewayReleaseDatabase
    {
        $this->agents
            ->answer('gateway.inspect-release-database', ['bytes' => 0])
            ->answer('gateway.migrate-release', ['changed' => true])
            ->answer('gateway.snapshot-release-database', function (array $input) use ($home, $connection): array {
                $directory = $home.'/backups';
                if (! is_dir($directory)) {
                    mkdir($directory, 0700);
                }
                $path = $directory.'/pre-'.$input['release'].'-20261025T120000Z-'.bin2hex(random_bytes(8)).'.sqlite';
                DB::connection($connection)->statement('VACUUM INTO ?', [$path]);
                chmod($path, 0600);

                return ['path' => $path];
            });

        return new SqliteGatewayReleaseDatabase($this->releaseCommands(), $this->layout, $home,
            $home.'/gateway-release-step.lock', connection: $connection, minimumFreeBytes: 0);
    }

    public function cleanup(): void
    {
        new Filesystem()->deleteDirectory($this->base);
    }

    private function scriptedAgents(): FakeAgentCommandRunner
    {
        return new FakeAgentCommandRunner()
            ->answer('gateway.inspect-releases', fn (): array => $this->inventory())
            ->answer('gateway.switch-release', function (array $input): array {
                $previous = $this->current;
                $this->select($input['release']);

                return ['previous' => $previous];
            })
            ->answer('gateway.check-release-forward', fn (): array => ['source' => $this->current, 'source_sha' => $this->preparedSha($this->current)])
            ->answer('gateway.inspect-release-source', ['origin' => self::Origin])
            ->answer('gateway.inspect-release-step', ['busy' => false])
            ->answer('gateway.check-release-migrations', ['complete' => true])
            ->answer('gateway.inspect-release-preparation', ['sha' => null, 'origin' => self::Origin])
            ->answer('gateway.prepare-release', function (array $input): array {
                $sha = $this->resolve($input['revision'])
                    ?? throw new AgentCommandRefused('gateway.prepare-release', 'gateway.release_commit_unknown', 'The commit is unknown.');
                $id = GatewayReleaseCommit::id($sha);
                $reused = $this->preparedSha($id) !== null;
                if (! $reused) {
                    $this->releases[$id] = ['sha' => $sha, 'modified' => time(), 'finished' => null];
                }

                return ['sha' => $sha, 'reused' => $reused];
            })
            ->answer('gateway.inspect-release-agent-delivery', ['commit' => str_repeat('a', 40), 'version' => '0.5.0', 'installed' => true])
            ->answer('gateway.finish-release', function (array $input): array {
                $this->prepared($input['sha']);

                return ['changed' => true];
            })
            ->answer('gateway.refresh-release-configuration', ['changed' => true])
            ->answer('gateway.remove-release', function (array $input): array {
                $changed = isset($this->releases[$input['release']]);
                unset($this->releases[$input['release']]);

                return ['changed' => $changed];
            });
    }

    /** @return array<string, mixed> the `gateway.inspect-releases` answer for the recorded state */
    private function inventory(): array
    {
        $commits = $incomplete = $finished = [];
        foreach ($this->releases as $id => $release) {
            if ($release['finished'] === null) {
                $incomplete[$id] = $release['modified'];

                continue;
            }
            $commits[$id] = $release['sha'];
            $finished[$id] = $release['finished'];
        }
        arsort($finished);
        $adopted = $this->current !== null && isset($commits[$this->current]);

        return [
            'current' => $this->current,
            'state' => $adopted ? 'adopted' : ($this->inPlace ? 'in_place' : 'unreadable'),
            'superseded' => false,
            'commits' => $commits,
            'incomplete' => $incomplete,
            'retained' => array_map(strval(...), array_keys($finished)),
        ];
    }

    /** The commit of a prepared release, or null when the release is missing or incomplete. */
    public function preparedSha(?string $id): ?string
    {
        if ($id === null || ($this->releases[$id]['finished'] ?? null) === null) {
            return null;
        }

        return $this->releases[$id]['sha'];
    }

    /** The full SHA of a known commit that starts with the revision. */
    public function resolve(string $revision): ?string
    {
        foreach ($this->commits as $sha) {
            if (str_starts_with($sha, $revision)) {
                return $sha;
            }
        }

        return null;
    }
}
