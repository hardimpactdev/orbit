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
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * A real release layout in a temporary directory: an origin repository with a Gateway-shaped tree,
 * the shared bare repository and sample release artifacts. The builder uses fake agent results;
 * filesystem artifacts support the remaining runtime and execution-owner consumers. No dependency
 * install or native release build runs in this fixture.
 */
final class GatewayReleaseFixture implements ProcessRunner
{
    /** Keeps the developer's Git configuration, such as an fsmonitor daemon, out of the fixture. */
    private const array Isolated = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];

    public readonly string $base;

    public readonly string $origin;

    /** Real PHP without host INI startup, for the core-only Artisan stand-in. */
    public readonly string $php;

    public readonly GatewayReleaseLayout $layout;

    /** @var list<list<string>> */
    public array $privileged = [];

    /** @var list<list<string>> */
    public array $commands = [];

    private readonly FixtureProcessRunner $native;

    private static ?self $seed = null;

    private static ?self $adoptedSeed = null;

    private function __construct()
    {
        $this->base = sys_get_temp_dir().'/orbit-release-'.Str::lower(Str::random(10));
        $this->origin = $this->base.'/origin';
        $this->php = $this->base.'/bin/php';
        $this->native = new FixtureProcessRunner;
        $this->layout = new GatewayReleaseLayout($this->base.'/orbit/apps/gateway', new class($this) implements GatewayReleaseObserver
        {
            public function __construct(private GatewayReleaseFixture $fixture) {}

            public function inspect(GatewayReleaseLayout $layout, ?string $runningApplication = null): GatewayReleaseInventory
            {
                return GatewayReleaseInventory::fromAgent($this->fixture->builderAgents()->run(
                    new Node(['name' => 'gateway']),
                    'gateway.inspect-releases',
                    ['checkout' => $layout->applicationPath, 'running_application' => $runningApplication],
                ));
            }
        });

        mkdir($this->base.'/bin', 0700, true);
    }

    private function initialize(): void
    {
        file_put_contents($this->php, "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY)." -n \"\$@\"\n");
        chmod($this->php, 0500);
        $this->git($this->base, 'init', '--quiet', '--initial-branch=main', $this->origin);
        $this->write('.gitignore', "/vendor/\n/REVISION\napps/*/vendor/\napps/gateway/.env\n");
        $this->write('apps/cli/composer.json', '{}');
        $this->write('apps/gateway/composer.json', '{}');
        $this->write('apps/gateway/public/index.php', "<?php\n");
        $this->write('apps/gateway/artisan', "<?php\n");
        $this->write('apps/gateway/storage/logs/.gitignore', "*\n!.gitignore\n");
        $this->write('apps/gateway/storage/framework/cache/.gitignore', "*\n!.gitignore\n");
        $this->write('apps/gateway/bootstrap/cache/.gitignore', "*\n!.gitignore\n");
        $this->commit('Initial Gateway');

        mkdir($this->layout->sharedPath(), 0700, true);
        $this->git($this->base, 'clone', '--quiet', '--no-local', '--bare', $this->origin, $this->layout->repositoryPath());
        file_put_contents($this->layout->environmentPath(), "APP_ENV=production\n");
    }

    public static function prepare(): void
    {
        if (self::$seed !== null) {
            return;
        }

        $seed = new self;
        $seed->initialize();
        self::$seed = $seed;
    }

    public static function prepareAdopted(): void
    {
        if (self::$adoptedSeed !== null) {
            return;
        }
        self::prepare();
        $seed = self::create();
        $sha = $seed->commit('Adopted release');
        $seed->releaseArtifact($sha);
        $seed->markPrepared($sha);
        symlink($seed->layout->linkTarget(substr($sha, 0, 12)), $seed->layout->currentPath());
        self::$adoptedSeed = $seed;
    }

    public static function create(bool $adopted = false): self
    {
        $seed = ($adopted ? self::$adoptedSeed : self::$seed)
            ?? throw new RuntimeException('Prepare the requested release fixture in beforeAll.');
        $fixture = new self;
        $copied = $fixture->native->run(new ProcessInvocation(['cp', '-a', '--', $seed->base.'/.', $fixture->base]));
        if (! $copied->succeeded()) {
            throw new RuntimeException('Could not copy the release fixture seed: '.$copied->stderr);
        }
        $fixture->relocateLinks($seed);
        $fixture->git($fixture->layout->repositoryPath(), 'remote', 'set-url', 'origin', $fixture->origin);
        if ($adopted) {
            $administrativePaths = glob($fixture->layout->repositoryPath().'/worktrees/*');
            if (! is_array($administrativePaths) || count($administrativePaths) !== 1) {
                throw new RuntimeException('The adopted seed must contain exactly one Git worktree.');
            }
            foreach ($administrativePaths as $administrativePath) {
                $original = trim((string) file_get_contents($administrativePath.'/gitdir'));
                if (! str_starts_with($original, $seed->base.'/')) {
                    throw new RuntimeException('The adopted seed worktree must be inside its own tree.');
                }
                $gitFile = $fixture->base.substr($original, strlen($seed->base));
                file_put_contents($administrativePath.'/gitdir', $gitFile."\n");
                $permissions = fileperms($gitFile) & 0777;
                chmod($gitFile, $permissions | 0200);
                file_put_contents($gitFile, 'gitdir: '.$administrativePath."\n");
                chmod($gitFile, $permissions);
            }
        }

        return $fixture;
    }

    private function relocateLinks(self $seed): void
    {
        // Do not follow directory links: changing a copy must never write into its seed.
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if (! $entry->isLink()) {
                continue;
            }
            $target = $entry->getLinkTarget();
            if (! str_starts_with($target, $seed->base.'/')) {
                continue;
            }
            $parent = $entry->getPath();
            $permissions = fileperms($parent) & 07777;
            chmod($parent, $permissions | 0200);
            unlink($entry->getPathname());
            symlink($this->base.substr($target, strlen($seed->base)), $entry->getPathname());
            chmod($parent, $permissions);
        }
    }

    public static function forget(): void
    {
        self::$adoptedSeed?->cleanup();
        self::$adoptedSeed = null;
        self::$seed?->cleanup();
        self::$seed = null;
    }

    public function releaseCommands(?AgentCommandRunner $agents = null): GatewayReleaseCommands
    {
        $node = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->first();
        if (! $node instanceof Node) {
            $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.1']);
            $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        }

        return new GatewayReleaseCommands($agents ?? $this->builderAgents());
    }

    private ?FakeAgentCommandRunner $builderAgents = null;

    /** Sample database artifacts for recovery consumers; snapshot behavior is tested in Rust. */
    public function database(string $home, ?string $connection = null): SqliteGatewayReleaseDatabase
    {
        $agents = $this->builderAgents()
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

        return new SqliteGatewayReleaseDatabase($this->releaseCommands($agents), $this->layout, $home,
            $home.'/gateway-release-step.lock', connection: $connection, minimumFreeBytes: 0);
    }

    /** Explicit outcomes for preparation tests; native behavior is covered by Rust. */
    public function builderAgents(): FakeAgentCommandRunner
    {
        return $this->builderAgents ??= new FakeAgentCommandRunner()
            ->answer('gateway.inspect-releases', fn (array $input): array => $this->inventory($input['running_application']))
            ->answer('gateway.switch-release', function (array $input): array {
                $previous = $this->inventory()['current'];
                $current = $this->layout->currentPath();
                if (is_link($current)) {
                    unlink($current);
                }
                symlink($this->layout->linkTarget($input['release']), $current);

                return ['previous' => $previous];
            })
            ->answer('gateway.check-release-forward', function (): array {
                $inventory = $this->inventory();

                return ['source' => $inventory['current'], 'source_sha' => $inventory['commits'][$inventory['current']] ?? null];
            })
            ->answer('gateway.inspect-release-source', ['origin' => 'https://github.com/hardimpactdev/orbit.git'])
            ->answer('gateway.inspect-release-step', ['busy' => false])
            ->answer('gateway.check-release-migrations', ['complete' => true])
            ->answer('gateway.inspect-release-preparation', ['sha' => null, 'origin' => $this->origin])
            ->answer('gateway.prepare-release', function (array $input): array {
                $sha = trim($this->git($this->origin, 'rev-parse', $input['revision']));
                $reused = is_file($this->layout->releasePath(substr($sha, 0, 12)).'/REVISION');
                $this->releaseArtifact($sha);

                return ['sha' => $sha, 'reused' => $reused];
            })
            ->answer('gateway.finish-release', function (array $input): array {
                $this->markPrepared($input['sha']);

                return ['changed' => true];
            })
            ->answer('gateway.refresh-release-configuration', ['changed' => true])
            ->answer('gateway.remove-release', function (array $input): array {
                $path = $this->layout->releasePath($input['release']);
                if (is_dir($path)) {
                    $this->thawDirectories($path);
                    $this->native->run(new ProcessInvocation(['rm', '-rf', '--', $path]));
                }

                return ['changed' => true];
            });
    }

    public function builder(?GatewayReleaseWebBuild $web = null): GatewayReleaseBuilder
    {
        return new GatewayReleaseBuilder(
            layout: $this->layout,
            commands: $this->releaseCommands($this->builderAgents()),
            readAccess: app(RepositoryReadAccess::class),
            web: $web ?? new NoWebBuild,
            checkoutAccess: function (string $application): void {
                $this->privileged[] = ['grant-access', $application];
            },
            stepLock: $this->base.'/home/gateway-release-step.lock',
        );
    }

    /** Agent responses from sample state. These fakes exercise no native path validation or Git ancestry. */
    private function inventory(?string $running = null): array
    {
        clearstatcache();
        $link = $this->layout->currentPath();
        $id = is_link($link) ? basename(readlink($link)) : null;
        $current = is_string($id) && GatewayReleaseCommit::isId($id) ? $id : null;
        $commits = $incomplete = $times = [];
        foreach (glob($this->layout->releasesPath().'/*', GLOB_ONLYDIR) ?: [] as $path) {
            $id = basename($path);
            if (! GatewayReleaseCommit::isId($id) || is_link($path)) {
                continue;
            }
            if (! is_file($path.'/REVISION')) {
                $incomplete[$id] = filemtime($path);

                continue;
            }
            $sha = trim(file_get_contents($path.'/REVISION'));
            if (GatewayReleaseCommit::isSha($sha) && str_starts_with($sha, $id)) {
                $commits[$id] = $sha;
                $times[$id] = filemtime($path.'/REVISION');
            }
        }
        uksort($times, static fn ($a, $b): int => [$times[$b], (string) $b] <=> [$times[$a], (string) $a]);
        ksort($commits);
        ksort($incomplete);

        return [
            'current' => $current,
            'state' => isset($commits[$current]) ? 'adopted' : (is_dir($link) && ! is_link($link) ? 'in_place' : 'unreadable'),
            'superseded' => $running !== null && is_link($link) && realpath($running) !== false && realpath($link.'/apps/gateway') !== realpath($running),
            'commits' => $commits, 'incomplete' => $incomplete, 'retained' => array_map(strval(...), array_keys($times)),
        ];
    }

    /** Static sample files consumed by runtime tests, not an implementation of preparation. */
    private function releaseArtifact(string $sha): void
    {
        $path = $this->layout->releasePath(substr($sha, 0, 12));
        if (is_dir($path)) {
            return;
        }
        $this->git($this->layout->repositoryPath(), 'fetch', '--quiet', $this->origin, $sha);
        $this->git($this->layout->repositoryPath(), 'worktree', 'add', '--quiet', '--detach', $path, $sha);
        $application = $path.'/apps/gateway';
        foreach ([$application.'/vendor', $path.'/apps/cli/vendor', $this->layout->storagePath().'/logs', $this->layout->storagePath().'/framework/cache/data'] as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0750, true);
            }
        }
        file_put_contents($application.'/vendor/autoload.php', '<?php');
        file_put_contents($path.'/apps/cli/vendor/autoload.php', '<?php');
        $this->git($path, 'update-index', '--skip-worktree', 'apps/gateway/storage/logs/.gitignore', 'apps/gateway/storage/framework/cache/.gitignore');
        $this->native->run(new ProcessInvocation(['rm', '-rf', '--', $application.'/storage']));
        symlink($this->layout->storagePath(), $application.'/storage');
        symlink($this->layout->environmentPath(), $application.'/.env');
        file_put_contents($this->layout->repositoryPath().'/info/exclude', "/apps/gateway/storage\n");
        chmod($application.'/bootstrap/cache', 0700);
        file_put_contents($application.'/bootstrap/cache/config.php', '<?php return '.var_export(['app' => ['version' => $sha]], true).';');
        chmod($application.'/bootstrap/cache/config.php', 0600);
    }

    private function markPrepared(string $sha): void
    {
        $path = $this->layout->releasePath(substr($sha, 0, 12));
        file_put_contents($path.'/REVISION', $sha."\n");
        chmod($path, 0555);
        chmod($path.'/.git', 0444);
        chmod($path.'/apps/gateway', 0555);
    }

    /**
     * Turns the fixture into a Gateway before adoption: no shared state, and an in-place clone of
     * the origin at its last commit, with an env file, an env backup, and some storage.
     */
    public function inPlaceCheckout(string $env = "APP_ENV=production\nAPP_VERSION=0.1.0\n"): string
    {
        $current = $this->layout->currentPath();
        exec('chmod -R u+w '.escapeshellarg($this->layout->sharedPath()).' 2>/dev/null; rm -rf '.escapeshellarg($this->layout->sharedPath()).' '.escapeshellarg($current));
        $this->git($this->base, 'clone', '--quiet', '--no-local', $this->origin, $current);
        $this->git($current, 'remote', 'set-url', 'origin', 'https://github.com/hardimpactdev/orbit.git');
        $sha = trim($this->git($current, 'rev-parse', 'HEAD'));
        $this->git($current, 'checkout', '--quiet', '--detach', $sha);
        file_put_contents($current.'/apps/gateway/.env', $env);
        chmod($current.'/apps/gateway/.env', 0600);
        file_put_contents($current.'/apps/gateway/.env.bak-deploy-1', "APP_ENV=old\n");
        mkdir($current.'/apps/gateway/storage/framework/cache/data/aa', 0750, true);
        file_put_contents($current.'/apps/gateway/storage/framework/cache/data/aa/lock', 'held');
        file_put_contents($current.'/apps/gateway/storage/logs/laravel.log', "one line\n");
        chmod($current.'/apps/gateway/storage/logs/laravel.log', 0640);

        return $sha;
    }

    public function write(string $path, string $contents): void
    {
        $file = $this->origin.'/'.$path;
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $contents);
    }

    public function commit(string $message): string
    {
        $this->git($this->origin, 'add', '--all');
        $this->git($this->origin, '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '--allow-empty', '-m', $message);

        return trim($this->git($this->origin, 'rev-parse', 'HEAD'));
    }

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->commands[] = $invocation->arguments;

        if ($invocation->arguments[0] === 'sudo') {
            $this->privileged[] = $invocation->arguments;

            return new CommandResult(0, '', '', 1, false);
        }

        return $this->native->run(new ProcessInvocation(
            arguments: $invocation->arguments,
            timeout: $invocation->timeout,
            input: $invocation->input,
            protectedInput: $invocation->protectedInput,
            environment: [...$invocation->environment, ...self::Isolated],
        ));
    }

    public function cleanup(): void
    {
        if (is_dir($this->base)) {
            $this->thawDirectories($this->base);
            exec('rm -rf -- '.escapeshellarg($this->base));
        }
    }

    private function thawDirectories(string $directory): void
    {
        // Restore access before opening traversal-only directories; never thaw files or symlink targets.
        chmod($directory, (fileperms($directory) & 0o7777) | 0o700);
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (! $entry->isLink() && $entry->isDir()) {
                $this->thawDirectories($entry->getPathname());
            }
        }
    }

    public function git(string $directory, string ...$arguments): string
    {
        $result = $this->native->run(new ProcessInvocation(['git', '-C', $directory, ...$arguments], timeout: 60.0, environment: self::Isolated));

        if (! $result->succeeded()) {
            throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.$result->stderr);
        }

        return $result->stdout;
    }
}
