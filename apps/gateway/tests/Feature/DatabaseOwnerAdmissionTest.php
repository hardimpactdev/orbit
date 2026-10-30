<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareOwnedDatabaseDestructionAction;
use App\Domain\DatabaseServers\DatabaseOperationCompletion;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Operation;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\EnvironmentConcurrencySchemaFixture;
use Tests\Support\ForkedGatewayProcess;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';

uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->update(['architecture' => 'amd64']);
    $this->instance->project->update(['root' => '.']);
    $this->instance->update(['branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $this->owned = DatabaseConnection::query()->create(['slug' => 'owned-before-removal', 'driver' => 'sqlite',
        'node_id' => $this->instance->node_id, 'path' => $this->instance->checkout_path.'/db.sqlite', 'owner_instance_id' => $this->instance->id]);
});

function admitDatabaseOwnerRemoval(Instance $instance): void
{
    DB::transaction(function () use ($instance): void {
        $removal = InstanceRemoval::query()->create(['id' => 'database-owner-removal-'.$instance->id, 'requested_instance_id' => $instance->id,
            'requested_name' => $instance->name, 'force' => true, 'inventory_digest' => str_repeat('a', 64), 'total' => 1,
            'status' => 'removing', 'current_step' => 'source_preparation']);
        $removal->members()->create(['position' => 0, 'instance_id' => $instance->id, 'project_id' => $instance->project_id,
            'node_id' => $instance->node_id, 'name' => $instance->name, 'environment' => 'development', 'source_layout' => $instance->source_layout,
            'repository_identity' => $instance->project->repository_identity, 'checkout_path' => $instance->checkout_path, 'root' => $instance->effectiveRoot(),
            'branch' => $instance->branch, 'starting_commit' => $instance->starting_commit, 'source_commit' => $instance->starting_commit,
            'common_repository_path' => $instance->checkout_path, 'linked_worktree_paths' => [$instance->checkout_path],
            'source_identity' => '12:34', 'source_digest' => str_repeat('a', 64)]);
        $instance->update(['status' => InstanceState::Removing]);
    });
}

it('refuses a new owned row after removal even when the owner was resolved earlier', function (): void {
    $pending = new DatabaseConnection(['slug' => 'new-owned', 'driver' => 'mysql', 'owner_instance_id' => $this->instance->id]);
    admitDatabaseOwnerRemoval($this->instance->fresh());
    expect($this->instance->status)->toBe(InstanceState::Active);
    expect(fn () => $pending->save())->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.owner_removing'));
    expect(DatabaseConnection::query()->where('slug', 'new-owned')->exists())->toBeFalse();
});

it('refuses adding or relinquishing ownership involving a Removing Instance', function (bool $add): void {
    $connection = $add ? DatabaseConnection::query()->create(['slug' => 'unowned', 'driver' => 'mysql']) : $this->owned;
    admitDatabaseOwnerRemoval($this->instance);
    expect(fn () => $connection->update(['owner_instance_id' => $add ? $this->instance->id : null]))->toThrow(ResourceOperationException::class);
    expect($connection->fresh()->owner_instance_id)->toBe($add ? null : $this->instance->id);
})->with([true, false]);

it('checks the stored old owner rather than a stale model original', function (): void {
    $stale = DatabaseConnection::query()->create(['slug' => 'stale', 'driver' => 'mysql']);
    $current = $stale->fresh();
    $current->update(['owner_instance_id' => $this->instance->id]);
    admitDatabaseOwnerRemoval($this->instance);
    expect(fn () => $stale->update(['slug' => 'stale-updated']))->toThrow(ResourceOperationException::class);
    expect($current->fresh()->slug)->toBe('stale');
});

it('refuses owned identity changes but permits effect proof and clone checkpoint projection', function (): void {
    admitDatabaseOwnerRemoval($this->instance);
    expect(fn () => $this->owned->update(['path' => $this->instance->checkout_path.'/changed.sqlite']))->toThrow(ResourceOperationException::class);
    $this->owned->refresh()->forceFill(['clone_step' => 'complete', 'creation_receipts' => []])->save();
    expect($this->owned->fresh()->clone_step->value)->toBe('complete')->and($this->owned->fresh()->creation_receipts)->toBe([]);
    $this->owned->delete();
    $this->assertModelMissing($this->owned);
});

it('permits ordinary additions and ownership changes before removal admission', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'new-owned', 'driver' => 'mysql', 'owner_instance_id' => $this->instance->id]);
    $connection->update(['owner_instance_id' => null]);
    expect($connection->fresh()->owner_instance_id)->toBeNull();
});

it('routes server adoption completion through owned identity admission', function (bool $removing): void {
    $intent = DatabaseExecutionIntent::query()->create(['target_type' => 'database_server', 'target_id' => $this->server->id,
        'node_id' => $this->server->node_id, 'payload' => ['commands' => [], 'projection' => ['adopted_connection' => $this->owned->slug]]]);
    $operation = Operation::query()->create(['kind' => 'database_server.create', 'target_type' => 'database_server', 'target_id' => $this->server->id,
        'node_id' => $this->server->node_id, 'status' => 'running', 'input' => ['intent_id' => $intent->id]]);
    if ($removing) {
        admitDatabaseOwnerRemoval($this->instance);
        expect(fn () => app(DatabaseOperationCompletion::class)->complete($operation, OperationOutcome::succeeded()))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.owner_removing'));
        expect($this->owned->fresh()->database_server_id)->toBeNull();
    } else {
        app(DatabaseOperationCompletion::class)->complete($operation, OperationOutcome::succeeded());
        expect($this->owned->fresh()->database_server_id)->toBe($this->server->id);
    }
})->with([true, false]);

beforeAll(fn () => EnvironmentConcurrencySchemaFixture::prepare(<<<'PHP'
<?php
[$script, $base, $database, $directory] = $argv;
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'DB_DATABASE' => $database, 'ORBIT_HOME' => $directory.'/orbit-home'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->useStoragePath($directory.'/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
PHP));
afterAll(fn () => EnvironmentConcurrencySchemaFixture::forget());

it('serializes owner admission and removal with a real SQLite transaction barrier', function (bool $removalFirst): void {
    $directory = sys_get_temp_dir().'/orbit-db-owner-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $database = $directory.'/gateway.sqlite';
    EnvironmentConcurrencySchemaFixture::copyTo($database);
    $workers = [];
    try {
        $setup = new ForkedGatewayProcess($database, $directory.'/setup', function (): int {
            $this->prepareServerDatabaseFixtures();
            $this->dbNode->update(['architecture' => 'amd64']);
            $this->instance->project->update(['root' => '.']);
            $this->instance->update(['branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);

            return 0;
        });
        $setup->run();
        expect($setup->isSuccessful())->toBeTrue($setup->getErrorOutput());
        foreach (['add', 'remove'] as $mode) {
            $holder = ($mode === 'remove') === $removalFirst;
            $worker = new ForkedGatewayProcess($database, $directory.'/'.$mode, function ($socket) use ($mode, $holder, $directory): int {
                $owner = Instance::query()->firstOrFail();
                $pending = new DatabaseConnection(['slug' => 'concurrent-owned', 'driver' => 'mysql', 'owner_instance_id' => $owner->id]);
                ForkedGatewayProcess::write($socket, 'ready');
                if (ForkedGatewayProcess::read($socket) !== 'begin') {
                    throw new RuntimeException('Expected begin.');
                }
                if ($holder) {
                    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($socket): void {
                        if ($event->connection->transactionLevel() !== 1) {
                            return;
                        }
                        ForkedGatewayProcess::write($socket, 'held');
                        if (ForkedGatewayProcess::read($socket) !== 'commit') {
                            throw new RuntimeException('Expected commit.');
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
                            throw new RuntimeException('Contender was not blocked.');
                        } catch (PDOException $error) {
                            if (($error->errorInfo[1] ?? null) !== 5) {
                                throw $error;
                            }
                            ForkedGatewayProcess::write($socket, 'blocked');
                        } finally {
                            $pdo->exec('PRAGMA busy_timeout=5000');
                        }
                        if (ForkedGatewayProcess::read($socket) !== 'retry') {
                            throw new RuntimeException('Expected retry.');
                        }
                    });
                }
                try {
                    if ($mode === 'add') {
                        $pending->save();
                        $result = ['added' => true];
                    } else {
                        $result = DB::transaction(function () use ($owner): array {
                            $artifact = app(PrepareOwnedDatabaseDestructionAction::class)->execute($owner);
                            admitDatabaseOwnerRemoval($owner);

                            return ['connections' => array_column($artifact['connections'], 'connection_id')];
                        });
                    }
                } catch (ResourceOperationException $error) {
                    $result = ['error_code' => $error->errorCode];
                }
                file_put_contents($directory.'/'.$mode.'.json', json_encode($result, JSON_THROW_ON_ERROR));

                return 0;
            });
            $workers[$mode] = $worker;
            $worker->start();
            expect($worker->receive())->toBe('ready');
        }
        $holder = $workers[$removalFirst ? 'remove' : 'add'];
        $contender = $workers[$removalFirst ? 'add' : 'remove'];
        $holder->send('begin');
        expect($holder->receive())->toBe('held');
        $contender->send('begin');
        expect($contender->receive())->toBe('blocked');
        $holder->send('commit');
        $holder->wait();
        $contender->send('retry');
        $contender->wait();
        foreach ($workers as $worker) {
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }
        $added = json_decode(file_get_contents($directory.'/add.json'), true, flags: JSON_THROW_ON_ERROR);
        $removed = json_decode(file_get_contents($directory.'/remove.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($added)->toBe($removalFirst ? ['error_code' => 'database.owner_removing'] : ['added' => true])
            ->and($removed['connections'])->toHaveCount($removalFirst ? 0 : 1);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        new Filesystem()->deleteDirectory($directory);
    }
})->with([true, false]);
