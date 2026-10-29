<?php

declare(strict_types=1);

use App\Actions\Instances\CloneInstanceDatabaseAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Instances\DatabaseClone\InstanceDatabaseClonePlanner;
use App\Domain\Instances\DatabaseClone\InstanceSqliteCloner;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakesEnvironmentSyncInspection;
use Tests\Support\LifecycleAgent;

const DATABASE_CLONE_ROOT_SECRET = 'clone-root-secret-77ab';

beforeEach(function (): void {
    app()->instance(InstanceDestinationGuard::class, new class implements InstanceDestinationGuard
    {
        public function assertUnoccupied(Node $node, StoragePath $destination): void {}
    });
    $this->configuration = new class implements DevelopmentInstanceConfigurator
    {
        public bool $laravel = true;

        public function inspect(Instance $instance): DevelopmentSourceProfile
        {
            return new DevelopmentSourceProfile('8.5', $this->laravel);
        }

        public function configureLaravelUrl(Instance $instance, string $url): void {}
    };
    app()->instance(DevelopmentInstanceConfigurator::class, $this->configuration);
    app()->instance(DevelopmentRouteProjector::class, new class implements DevelopmentRouteProjector
    {
        public function converge(Instance $instance, Route $route): void {}
    });
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    app()->instance(DevelopmentInstanceSourceLifecycle::class, new class implements DevelopmentInstanceSourceLifecycle
    {
        public function prepare(Instance $instance, bool $allowExisting): void {}

        public function inspectPrepared(Instance $instance): void {}

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            return new DevelopmentSourceResolution($instance->branch_override ?? $instance->name, str_repeat('a', 40));
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            return $this->resolve($instance);
        }
    });
    $removal = new DatabaseCloneRemovalFakes;
    app()->instance(DevelopmentInstanceSourceRemoval::class, $removal);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $removal);
    app()->instance(InstanceRemovalProjector::class, $removal);
    $this->environment = new DatabaseCloneEnvironmentFakes;
    app()->instance(InstanceOperationPreflight::class, $this->environment);
    app()->instance(InstanceEnvironmentReader::class, $this->environment);
    app()->instance(InstanceEnvironmentWriter::class, $this->environment);
    app()->instance(InstanceTestEnvironmentWriter::class, $this->environment);
    $this->admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $this->admin);
    $this->sqlite = new DatabaseCloneSqliteFake;
    app()->instance(InstanceSqliteCloner::class, $this->sqlite);

    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->default = Instance::query()->create([
        'project_id' => $this->project->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
});

/** Attach a source connection to the default Instance under prefix DB. */
function database_clone_source(Instance $default, array $attributes): DatabaseConnection
{
    $connection = DatabaseConnection::query()->create(['slug' => 'acme-default', ...$attributes]);
    DatabaseConnectionTarget::query()->create([
        'instance_id' => $default->id,
        'database_connection_id' => $connection->id,
        'prefix' => 'DB',
    ]);

    return $connection;
}

function database_clone_server(Node $node): DatabaseServer
{
    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'beast-mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'mysql:8.4', 'command' => ['mysqld'], 'environment' => [], 'ports' => [], 'volumes' => []],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    return DatabaseServer::query()->create([
        'slug' => 'beast-mysql',
        'node_id' => $node->id,
        'process_id' => $process->id,
        'tag' => '8.4',
        'port' => 3306,
        'root_password' => DATABASE_CLONE_ROOT_SECRET,
        'status' => LifecycleStatus::Active,
    ]);
}

/** @return array<string, mixed> */
function database_clone_request(Project $project, Node $node, string $name = 'feature-x'): array
{
    return ['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name];
}

/** @return array<string, string> */
function database_clone_env(string $contents): array
{
    $values = [];

    foreach (array_filter(explode("\n", $contents)) as $line) {
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = trim($value, '"');
    }

    return $values;
}

describe('.env.testing on synchronization', function (): void {
    beforeEach(function (): void {
        $server = database_clone_server($this->node);
        database_clone_source($this->default, [
            'driver' => 'mysql',
            'node_id' => $this->node->id,
            'database_server_id' => $server->id,
            'host' => '10.44.0.3',
            'port' => 3306,
            'database' => 'acme_default',
            'username' => 'acme_default',
            'password' => 'default-secret',
        ]);
    });

    // The writer seeds a missing file with these contents and merges only the managed keys into an existing one.
    it('sends the full .env.testing seed with the test database and records the test database', function (): void {
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $instance = Instance::query()->where('name', 'feature-x')->sole();
        $this->environment->testingContents = null;

        $response = $this->call('POST', "/api/v1/instances/{$instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
            ->assertOk()
            ->assertJsonMissingPath('data.testing');

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect(database_clone_env((string) $this->environment->testingContents))->toMatchArray([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:kept',
            'DB_DATABASE' => 'acme_feature_x_test',
            'DB_HOST' => '10.44.0.3',
        ])
            ->and($this->environment->testingManagedKeys)->toBe(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'])
            ->and($activity->properties?->toArray()['testing'] ?? null)->toBe([
                'file' => '.env.testing',
                'status' => 'written',
                'test_database' => 'acme_feature_x_test',
            ]);
    });

    it('never writes a .env.testing that Git tracks and records the test database instead', function (): void {
        $this->environment->testingTracked = true;
        $this->postJson('/api/v1/instances', database_clone_request($this->project, $this->node))->assertCreated();
        $instance = Instance::query()->where('name', 'feature-x')->sole();

        $response = $this->call('POST', "/api/v1/instances/{$instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
            ->assertOk();

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect($this->environment->testingContents)->toBeNull()
            ->and($this->environment->contents)->not->toBeNull()
            ->and($activity->properties?->toArray()['testing'] ?? null)->toBe([
                'file' => '.env.testing',
                'status' => 'skipped_tracked',
                'test_database' => 'acme_feature_x_test',
            ]);
    });
});

final class DatabaseCloneSqliteFake implements InstanceSqliteCloner
{
    /** @var list<array{source: string, source_path: string, target: string, target_path: string}> */
    public array $copies = [];

    /** @var list<string> */
    public array $removed = [];

    public bool $failRemove = false;

    public function copy(Instance $source, string $sourcePath, Instance $target, string $targetPath): void
    {
        $this->copies[] = ['source' => $source->name, 'source_path' => $sourcePath, 'target' => $target->name, 'target_path' => $targetPath];
    }

    public function remove(Instance $owner, string $path): void
    {
        if ($this->failRemove) {
            throw new ResourceOperationException('instance.database_clone_failed', 'The SQLite database could not be removed.', 502);
        }

        $this->removed[] = $path;
    }
}

final class DatabaseCloneEnvironmentFakes implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight, InstanceTestEnvironmentWriter
{
    use FakesEnvironmentSyncInspection;

    public ?string $contents = null;

    public ?string $testingContents = null;

    /** @var list<string> */
    public array $testingManagedKeys = [];

    public bool $testingTracked = false;

    public int $reads = 0;

    public bool $missing = false;

    public string $source = "APP_KEY=base64:kept\nAPP_URL=http://localhost\nDB_CONNECTION=sqlite\n";

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void {}

    public function read(InstanceEnvironmentContext $context): string
    {
        $this->reads++;

        if ($this->missing) {
            throw new ResourceOperationException('env.import_source_missing', 'The recorded Instance environment file does not exist.', 404);
        }

        return $this->contents ?? $this->source;
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        $this->contents = $contents;

        return InstanceEnvironmentWriteResult::changed();
    }

    public function mergeTesting(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents, array $managedKeys): InstanceEnvironmentWriteResult
    {
        $this->testingManagedKeys = $managedKeys;

        if ($this->testingTracked) {
            return InstanceEnvironmentWriteResult::tracked();
        }

        $this->testingContents = $contents;

        return InstanceEnvironmentWriteResult::changed();
    }
}

final class DatabaseCloneRemovalFakes implements DevelopmentInstanceSourceFinalizer, DevelopmentInstanceSourceRemoval, InstanceRemovalProjector
{
    public function inspect(Instance $instance, bool $force, bool $inspectContent = true): InstanceSourceInventory
    {
        $payload = ['instance_id' => $instance->id, 'checkout_path' => $instance->checkout_path];

        return new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: $instance->source_layout,
            repositoryIdentity: $instance->project->repository_identity,
            checkoutPath: $instance->checkout_path,
            root: dirname(dirname($instance->checkout_path)),
            branch: $instance->branch,
            startingCommit: $instance->starting_commit,
            commonRepositoryPath: $instance->checkout_path,
            sourceIdentity: "test:{$instance->id}",
            linkedWorktreePaths: [$instance->checkout_path],
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function remove(Instance $instance, InstanceSourceInventory $inventory, bool $force): void {}

    public function prepare(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): void {}

    public function revalidate(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): InstanceSourceRevalidationState
    {
        return $member->source_finalized_at === null ? InstanceSourceRevalidationState::Present : InstanceSourceRevalidationState::Completed;
    }

    public function inspectRecorded(
        InstanceRemovalMember $member,
        InstanceSourceRevalidationState $state,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceInventory {
        $instance = Instance::query()->with('project')->findOrFail($member->instance_id);

        return $this->inspect($instance, true);
    }

    public function finalize(InstanceRemovalMember $member, ?InstanceSourceRevalidationExpectation $expectation = null): string
    {
        return hash('sha256', "receipt\0{$member->source_digest}");
    }

    public function clearRouteTarget(InstanceRemovalMember $member): string
    {
        $route = Route::query()->find($member->route_id);

        if (! $route instanceof Route) {
            return 'deleted';
        }

        $route->targets()->where('instance_id', $member->instance_id)->delete();
        $route->delete();

        return 'deleted';
    }

    public function withdrawPhpPool(InstanceRemovalMember $member): void {}

    public function cleanupRuntime(InstanceRemovalMember $member): void {}
}

it('refuses cloning over an unrecorded MySQL database or user before destructive SQL', function (string $query, string $name): void {
    $server = database_clone_server($this->node);
    database_clone_source($this->default, [
        'driver' => 'mysql', 'database_server_id' => $server->id, 'host' => '10.44.0.3', 'port' => 3306,
        'database' => 'acme_default', 'username' => 'acme_default', 'password' => 'default-secret',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'feature-x',
        'checkout_path' => '/srv/orbit/apps/acme/feature-x', 'status' => InstanceState::Active,
    ]);
    InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => 'APP_ENV', 'env_value' => 'testing']);
    $plan = app(InstanceDatabaseClonePlanner::class)->plan($this->project, 'feature-x');
    $this->admin->rows = [$query => [$name]];

    expect(fn () => app(CloneInstanceDatabaseAction::class)->execute($instance, $plan))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.database_clone_failed');
            $cause = $exception->getPrevious();
            expect($cause)->toBeInstanceOf(ResourceOperationException::class)
                ->and($cause->errorCode)->toBe('database.name_conflict');
        });
    expect($this->admin->sql())->not->toContain('DROP DATABASE')
        ->and($this->admin->sql())->not->toContain('CREATE USER')
        ->and($this->admin->sql())->not->toContain('ALTER USER')
        ->and($this->admin->copies)->toBe([])
        ->and(DatabaseConnection::query()->where('slug', 'acme-feature-x')->exists())->toBeFalse();
})->with([
    'database' => ['SCHEMA_NAME =', 'acme_feature_x'],
    'user' => ['FROM mysql.user', 'acme_feature_x'],
]);

it('refuses a retained clone aliasing its source before reset or failure cleanup', function (bool $sameInstance): void {
    $server = database_clone_server($this->node);
    $source = database_clone_source($this->default, [
        'driver' => 'mysql', 'database_server_id' => $server->id, 'database' => 'acme_default', 'username' => 'acme_default', 'password' => 'default-secret',
    ]);
    $plan = app(InstanceDatabaseClonePlanner::class)->plan($this->project, 'feature-x');
    $target = $sameInstance ? $this->default : Instance::query()->create([
        'project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'feature-x',
        'checkout_path' => '/srv/orbit/apps/acme/feature-x', 'status' => InstanceState::Active,
    ]);
    if (! $sameInstance) {
        DatabaseConnection::query()->create(['slug' => 'acme-feature-x', 'driver' => 'mysql', 'owner_instance_id' => $target->id,
            'database_server_id' => $server->id, 'database' => $source->database, 'username' => $source->username]);
    }

    expect(fn () => app(CloneInstanceDatabaseAction::class)->execute($target, $plan))->toThrow(ResourceOperationException::class, 'different databases');
    expect($this->admin->statements)->toBe([])->and($this->admin->copies)->toBe([]);
    $this->assertModelExists($source);
})->with([true, false]);
