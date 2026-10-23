<?php

declare(strict_types=1);

use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Actions\Instances\UpdateInstanceEnvironmentAction;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentRouteDomain;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Dotenv\Dotenv;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\EnvironmentConcurrencySchemaFixture;
use Tests\Support\ForkedGatewayProcess;

beforeAll(fn () => EnvironmentConcurrencySchemaFixture::prepare(orb207_concurrency_worker_script()));
afterAll(fn () => EnvironmentConcurrencySchemaFixture::forget());

it('copies the complete migrated concurrency schema and reference rows without sharing writes', function (): void {
    $directory = sys_get_temp_dir().'/orbit-env-schema-copies-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    try {
        EnvironmentConcurrencySchemaFixture::copyTo($directory.'/first.sqlite');
        EnvironmentConcurrencySchemaFixture::copyTo($directory.'/second.sqlite');
        $first = new PDO('sqlite:'.$directory.'/first.sqlite');
        $second = new PDO('sqlite:'.$directory.'/second.sqlite');
        $schema = 'SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type, name';
        expect($first->query($schema)->fetchAll(PDO::FETCH_ASSOC))->toBe($second->query($schema)->fetchAll(PDO::FETCH_ASSOC))
            ->and((int) $second->query('SELECT COUNT(*) FROM migrations')->fetchColumn())->toBeGreaterThan(0)
            ->and($second->query('SELECT id FROM project_document_storages')->fetchColumn())->toBe(1)
            ->and($second->query('SELECT id FROM task_vm_image_recipe')->fetchColumn())->toBe(1);
        $first->exec('CREATE TABLE private_case (body BLOB)');
        $first->exec("INSERT INTO private_case VALUES (X'0001FF')");
        EnvironmentConcurrencySchemaFixture::copyTo($directory.'/third.sqlite');
        $third = new PDO('sqlite:'.$directory.'/third.sqlite');
        foreach ([$second, $third] as $copy) {
            expect((int) $copy->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'private_case'")->fetchColumn())->toBe(0);
        }
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('serializes concurrent complete-result validation through SQLite immediate transactions', function (string $holder): void {
    $directory = sys_get_temp_dir().'/orbit-env-concurrency-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $database = "{$directory}/gateway.sqlite";
    EnvironmentConcurrencySchemaFixture::copyTo($database);
    $setup = $import = $update = null;

    try {
        $setup = new ForkedGatewayProcess($database, $directory.'/setup.output', function (): int {
            [$instance] = orb207_concurrency_fixture();
            $value = new InstanceEnvironmentValue(['env_value' => 'value'])->getAttributes()['env_value'];
            $rows = [];
            for ($index = 0; $index < 1_023; $index++) {
                $rows[] = ['instance_id' => $instance->id, 'env_key' => "KEY_{$index}", 'env_value' => $value];
            }
            DB::table('instance_environment_values')->insert($rows);

            return 0;
        });
        $setup->run();
        expect($setup->isSuccessful())->toBeTrue($setup->getErrorOutput());

        $import = new ForkedGatewayProcess($database, $directory.'/import.output', fn ($socket): int => orb207_concurrency_worker($socket, $directory, 'import', $holder === 'import'));
        $update = new ForkedGatewayProcess($database, $directory.'/update.output', fn ($socket): int => orb207_concurrency_worker($socket, $directory, 'update', $holder === 'update'));
        $import->start();
        $update->start();

        expect($import->receive())->toBe('ready')->and($update->receive())->toBe('ready');
        $first = $holder === 'import' ? $import : $update;
        $second = $holder === 'import' ? $update : $import;
        $first->send('begin');
        expect($first->receive())->toBe('transaction-held');
        $second->send('begin');
        expect($second->receive())->toBe('transaction-blocked');
        expect($import->isRunning())->toBeTrue()->and($update->isRunning())->toBeTrue();
        $first->send('commit');
        $first->wait();
        $second->send('retry');
        $second->wait();

        expect($import->isSuccessful())->toBeTrue()->and($update->isSuccessful())->toBeTrue();
        $results = [
            json_decode((string) file_get_contents("{$directory}/import.result"), true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents("{$directory}/update.result"), true, flags: JSON_THROW_ON_ERROR),
        ];
        $statuses = collect($results)->pluck('status')->sort()->values()->all();

        expect($statuses)
            ->toBe(['invalid', 'ok'])
            ->and(collect($results)->firstWhere('status', 'invalid')['code'] ?? null)
            ->toBe('env.configuration_invalid');

        $inspect = new PDO('sqlite:'.$database);
        $stored = [
            'count' => (int) $inspect->query('SELECT COUNT(*) FROM instance_environment_values')->fetchColumn(),
            'keys' => $inspect->query("SELECT env_key FROM instance_environment_values WHERE env_key IN ('EXTRA', 'KEY_0', 'KEY_1023') ORDER BY env_key")->fetchAll(PDO::FETCH_COLUMN),
        ];
        expect($stored['count'])
            ->toBe(1_024)
            ->and($stored['keys'])
            ->toBe($holder === 'import' ? ['KEY_0', 'KEY_1023'] : ['EXTRA', 'KEY_0']);
    } finally {
        $setup?->stop();
        $import?->stop();
        $update?->stop();
        new Filesystem()->deleteDirectory($directory);
    }
})->with(['import first' => 'import', 'update first' => 'update']);

it('holds one cross-process owner through synchronization so a later update remains stored-only', function (): void {
    $directory = sys_get_temp_dir().'/orbit-env-sync-concurrency-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $database = "{$directory}/gateway.sqlite";
    EnvironmentConcurrencySchemaFixture::copyTo($database);
    $setup = $sync = $update = null;

    try {
        $setup = new ForkedGatewayProcess($database, $directory.'/setup.output', function (): int {
            [$instance] = orb207_concurrency_fixture();
            $instance->environmentValues()->create(['env_key' => 'INITIAL', 'env_value' => 'before-sync']);

            return 0;
        });
        $setup->run();
        expect($setup->isSuccessful())->toBeTrue($setup->getErrorOutput());
        $sync = new ForkedGatewayProcess($database, $directory.'/sync.output', fn ($socket): int => orb212_sync_concurrency_worker($socket, $directory, 'sync'));
        $sync->start();
        expect($sync->receive())->toBe('sync-ready');

        $update = new ForkedGatewayProcess($database, $directory.'/update.output', fn ($socket): int => orb212_sync_concurrency_worker($socket, $directory, 'update'));
        $update->start();
        expect($update->receive())->toBe('update-blocked');
        expect($update->isRunning())->toBeTrue()->and(file_exists("{$directory}/update.result"))->toBeFalse();

        $sync->send('release');
        $sync->wait();
        $update->send('retry');
        $update->wait();

        expect($sync->isSuccessful())
            ->toBeTrue()
            ->and($update->isSuccessful())
            ->toBeTrue()
            ->and(json_decode((string) file_get_contents("{$directory}/sync.result"), true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray(['operation' => 'sync', 'changed' => true, 'key_count' => 1])
            ->and(json_decode(
                (string) file_get_contents("{$directory}/update.result"),
                true,
                flags: JSON_THROW_ON_ERROR,
            ))
            ->toMatchArray(['operation' => 'update', 'changed' => true, 'key_count' => 2]);

        $synced = (string) file_get_contents("{$directory}/sync.contents");
        expect($synced)
            ->toContain('INITIAL=')
            ->not->toContain('PENDING');

        expect(json_decode((string) file_get_contents("{$directory}/inspect.result"), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['INITIAL' => 'before-sync', 'PENDING' => 'after-sync']);
    } finally {
        $setup?->stop();
        $sync?->stop();
        $update?->stop();
        new Filesystem()->deleteDirectory($directory);
    }
});

it('refuses a stale update after the supported removal boundary enters removing', function (): void {
    [$instance] = orb207_concurrency_fixture();
    $instance->environmentValues()->create(['env_key' => 'EXISTING', 'env_value' => 'kept']);
    $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, false);

    orb207_begin_recorded_removal($instance);

    try {
        app(InstanceEnvironmentStore::class)->update($context, 'NEW', 'must-not-attach');
        $this->fail('The stale update unexpectedly committed.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('env.owner_changed');
    }

    expect($instance->refresh()->status)
        ->toBe(InstanceState::Removing)
        ->and($instance->removalMember)
        ->not
        ->toBeNull()
        ->and(InstanceEnvironmentValue::query()->pluck('env_key')->all())
        ->toBe(['EXISTING']);
});

it('refuses a stale import across the recorded Route domain transition without a partial change', function (): void {
    [$instance, $route] = orb207_concurrency_fixture();
    $instance->environmentValues()->create(['env_key' => 'EXISTING', 'env_value' => 'kept']);
    $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);

    DB::transaction(function () use ($route, $instance): void {
        $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
        $replacement = Route::query()->create([
            'project_id' => $locked->project_id,
            'node_id' => $locked->node_id,
            'domain' => 'next.example.test',
            'provenance' => $locked->provenance,
            'publication' => $locked->publication,
            'status' => RouteStatus::Pending,
            'replaces_route_id' => $locked->id,
            'replacement_step' => RouteReplacementStep::Reserved,
        ]);
        $replacement->targets()->create([
            'instance_id' => $instance->id,
            'position' => 0,
        ]);
        $locked->update(['replaced_by_route_id' => $replacement->id]);
    });

    try {
        app(InstanceEnvironmentStore::class)->import(
            $context,
            [
                'EXISTING' => 'replaced',
                'NEW' => 'must-not-attach',
            ],
            true,
        );
        $this->fail('The stale import unexpectedly committed.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('env.owner_changed');
    }

    expect(InstanceEnvironmentValue::query()->pluck('env_key')->all())
        ->toBe(['EXISTING'])
        ->and($instance->environmentValues()->sole()->env_value)
        ->toBe('kept');
});

it('renders stored production values for candidate and previous Route domains without changing storage', function (): void {
    [$instance, $route] = orb207_concurrency_fixture();
    $instance->node->roles()->where('role', RoleName::AppDev->value)->delete();
    $instance->node->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $instance->update([
        'environment' => 'production',
        'checkout_path' => '/srv/orbit/apps/concurrency/releases/1',
        'production_home' => '/srv/orbit/apps/concurrency',
        'production_user' => 'orbit-production',
    ]);
    $instance->environmentValues()->createMany([
        ['env_key' => 'APP_KEY', 'env_value' => 'base64:literal-key'],
        ['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}'],
        ['env_key' => 'OTHER', 'env_value' => 'literal'],
    ]);
    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'node_id' => $route->node_id,
        'domain' => 'next.example.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => RouteReplacementStep::RouterCaddy,
    ]);
    $replacement->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['replaced_by_route_id' => $replacement->id]);
    $preflight = new class implements InstanceOperationPreflight
    {
        /** @var list<string> */
        public array $domains = [];

        public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void {}

        public function assertEnvironmentWritable(
            InstanceEnvironmentContext $context,
            int $requiredCapacityBytes,
        ): void {
            $this->domains[] = $context->routeDomain;
        }
    };
    $writer = new class implements InstanceEnvironmentWriter
    {
        /** @var list<string> */
        public array $contents = [];

        public function write(
            InstanceEnvironmentContext $context,
            string $contents,
        ): InstanceEnvironmentWriteResult {
            $this->contents[] = $contents;

            return InstanceEnvironmentWriteResult::changed();
        }
    };
    app()->instance(InstanceOperationPreflight::class, $preflight);
    app()->instance(InstanceEnvironmentWriter::class, $writer);
    $synchronizer = app(SynchronizeInstanceEnvironmentAction::class);

    $synchronizer->synchronizeRouteDomain($instance, InstanceEnvironmentRouteDomain::Candidate);
    $synchronizer->synchronizeRouteDomain($instance, InstanceEnvironmentRouteDomain::Authoritative);

    expect($preflight->domains)
        ->toBe(['next.example.test', 'concurrency.example.test'])
        ->and(Dotenv::parse($writer->contents[0]))
        ->toBe([
            'APP_KEY' => 'base64:literal-key',
            'APP_URL' => 'https://next.example.test',
            'OTHER' => 'literal',
        ])
        ->and(Dotenv::parse($writer->contents[1]))
        ->toBe([
            'APP_KEY' => 'base64:literal-key',
            'APP_URL' => 'https://concurrency.example.test',
            'OTHER' => 'literal',
        ])
        ->and($instance->environmentValues()->orderBy('env_key')->pluck('env_value')->all())
        ->toBe(['base64:literal-key', 'https://{{instance.domain}}', 'literal']);
});

it('marks every submitted and parsed value frame as sensitive', function (): void {
    [$instance] = orb207_concurrency_fixture();
    $sentinel = 'arbitrary-visible-value-'.Str::random(12);
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'KEY', $sentinel."\0");
        $this->fail('The invalid value unexpectedly committed.');
    } catch (ResourceOperationException $exception) {
        $trace = $exception->getTrace();
        $frames = collect($trace)->whereIn('function', ['execute', 'update', 'validate']);

        expect($frames->pluck('function')->all())->toContain('execute', 'update', 'validate');

        foreach ($frames as $frame) {
            expect(collect($frame['args'] ?? [])
                ->contains(
                    static fn (mixed $argument): bool => $argument instanceof SensitiveParameterValue,
                ))
                ->toBeTrue()
                ->and(json_encode($frame['args'] ?? [], JSON_PARTIAL_OUTPUT_ON_ERROR))
                ->not->toContain($sentinel);
        }
    } finally {
        if (is_string($previous)) {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    expect(InstanceEnvironmentValue::query()->count())->toBe(0);
});

/** @return array{Instance, Route} */
function orb207_concurrency_fixture(): array
{
    $project = Project::query()->create([
        'name' => 'Concurrency',
        'slug' => 'concurrency',
        'repository_url' => 'https://example.test/concurrency.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'concurrency-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.210',
        'wireguard_ip' => '10.44.0.210',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'source_layout' => InstanceSourceLayout::Checkout,
        'checkout_path' => '/srv/orbit/apps/concurrency/default',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'concurrency.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);

    return [$instance->fresh(['project', 'node']), $route->fresh()];
}

function orb207_begin_recorded_removal(Instance $instance): void
{
    DB::transaction(function () use ($instance): void {
        $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
        $route = $locked->routes()->sole();
        $removal = InstanceRemoval::query()->create([
            'id' => (string) Str::uuid(),
            'requested_instance_id' => $locked->id,
            'requested_name' => $locked->name,
            'force' => false,
            'inventory_digest' => str_repeat('b', 64),
            'total' => 1,
            'status' => InstanceRemovalStatus::Removing,
            'current_step' => InstanceRemovalStep::SourcePreparation,
        ]);
        $removal
            ->members()
            ->create([
                'position' => 0,
                'instance_id' => $locked->id,
                'project_id' => $locked->project_id,
                'node_id' => $locked->node_id,
                'route_id' => $route->id,
                'name' => $locked->name,
                'environment' => $locked->defaultAppEnv(),
                'source_layout' => $locked->source_layout,
                'repository_identity' => $locked->project->repository_identity,
                'checkout_path' => $locked->checkout_path,
                'root' => $locked->effectiveRoot(),
                'branch' => $locked->branch,
                'starting_commit' => $locked->starting_commit,
                'source_commit' => $locked->starting_commit,
                'common_repository_path' => $locked->checkout_path,
                'source_identity' => "test:{$locked->id}",
                'linked_worktree_paths' => [$locked->checkout_path],
                'source_digest' => str_repeat('c', 64),
            ]);
        $locked->update(['status' => InstanceState::Removing]);
    });
}

function orb207_concurrency_worker_script(): string
{
    return <<<'PHP'
        <?php

        declare(strict_types=1);

        [$script, $base, $database, $directory, $mode] = $argv;
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'ORBIT_HOME' => "{$directory}/orbit-home",
        ];
        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        require "{$base}/vendor/autoload.php";
        $app = require "{$base}/bootstrap/app.php";
        $app->useStoragePath("{$directory}/storage");
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
        PHP;
}

/** @param resource $socket */
function orb207_concurrency_worker(mixed $socket, string $directory, string $mode, bool $holder): int
{
    $instance = Instance::query()->firstOrFail();
    $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, false);
    ForkedGatewayProcess::write($socket, 'ready');
    if (ForkedGatewayProcess::read($socket) !== 'begin') {
        throw new RuntimeException('The environment transaction barrier was not released.');
    }

    if ($holder) {
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($socket): void {
            if ($event->connection->transactionLevel() !== 1) {
                return;
            }
            ForkedGatewayProcess::write($socket, 'transaction-held');
            if (ForkedGatewayProcess::read($socket) !== 'commit') {
                throw new RuntimeException('The environment transaction holder was not released.');
            }
        });
    } else {
        DB::connection()->beforeStartingTransaction(function () use ($socket): void {
            if (DB::connection()->transactionLevel() !== 0) {
                return;
            }
            $pdo = DB::connection()->getPdo();
            $pdo->exec('PRAGMA busy_timeout=0');
            try {
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
                $pdo->exec('ROLLBACK');
                ForkedGatewayProcess::write($socket, 'unexpectedly-acquired');
            } catch (PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 5) {
                    throw $exception;
                }
                ForkedGatewayProcess::write($socket, 'transaction-blocked');
            } finally {
                $pdo->exec('PRAGMA busy_timeout=5000');
            }
            if (ForkedGatewayProcess::read($socket) !== 'retry') {
                throw new RuntimeException('The environment transaction contender was not released.');
            }
        });
    }

    try {
        $store = app(InstanceEnvironmentStore::class);
        if ($mode === 'import') {
            $store->import($context, ['KEY_1023' => 'value'], false);
        } else {
            $store->update($context, 'EXTRA', 'value');
        }
        $result = ['status' => 'ok'];
    } catch (ResourceOperationException $exception) {
        $result = ['status' => 'invalid', 'code' => $exception->errorCode];
    }
    file_put_contents("{$directory}/{$mode}.result", json_encode($result, JSON_THROW_ON_ERROR));

    return 0;
}

/** @param resource $socket */
function orb212_sync_concurrency_worker(mixed $socket, string $directory, string $mode): int
{
    $instance = Instance::query()->firstOrFail();
    app()->instance(InstanceEnvironmentOperationLock::class, new NativeInstanceEnvironmentOperationLock(
        $directory.'/locks', new CommandDeadline,
        wait: function (int $microseconds) use ($socket): void {
            ForkedGatewayProcess::write($socket, 'update-blocked');
            if (ForkedGatewayProcess::read($socket) !== 'retry') {
                throw new RuntimeException('The environment lock contender was not released.');
            }
        },
    ));
    if ($mode === 'sync') {
        app()->instance(InstanceOperationPreflight::class, new class implements InstanceOperationPreflight
        {
            public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void {}

            public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void {}
        });
        $writer = new class($socket, $directory) implements InstanceEnvironmentReader, InstanceEnvironmentWriter
        {
            /** @param resource $socket */
            public function __construct(private mixed $socket, private string $directory) {}

            public function read(InstanceEnvironmentContext $context): string
            {
                return '';
            }

            public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
            {
                file_put_contents("{$this->directory}/sync.contents", $contents);
                ForkedGatewayProcess::write($this->socket, 'sync-ready');
                if (ForkedGatewayProcess::read($this->socket) !== 'release') {
                    throw new RuntimeException('The synchronization writer was not released.');
                }

                return InstanceEnvironmentWriteResult::changed();
            }
        };
        app()->instance(InstanceEnvironmentReader::class, $writer);
        app()->instance(InstanceEnvironmentWriter::class, $writer);
        $result = app(SynchronizeInstanceEnvironmentAction::class)->execute($instance);
    } else {
        $result = app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'PENDING', 'after-sync');
        file_put_contents("{$directory}/inspect.result", json_encode(
            $instance->environmentValues()->orderBy('env_key')->pluck('env_value', 'env_key')->all(),
            JSON_THROW_ON_ERROR,
        ));
    }
    file_put_contents("{$directory}/{$mode}.result", json_encode($result->toArray(), JSON_THROW_ON_ERROR));

    return 0;
}
