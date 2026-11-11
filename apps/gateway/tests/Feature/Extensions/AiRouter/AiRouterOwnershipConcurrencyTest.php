<?php

declare(strict_types=1);

use App\Actions\Instances\AttachInstanceNodeServiceTargetAction;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process as ShellProcess;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;
use Orbit\Extensions\AiRouter\Domain\AiRouterSettings;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

beforeEach(function (): void {
    expect(Artisan::call('migrate:fresh'))->toBe(0);
    $file = tempnam(sys_get_temp_dir(), 'orbit-owner-claim-');
    unlink($file);
    DB::statement('VACUUM INTO ?', [$file]);
    $default = DB::getDefaultConnection();
    foreach (['ownership-holder', 'attachment-contender'] as $name) {
        config(["database.connections.{$name}" => [...config("database.connections.{$default}"), 'database' => $file, 'busy_timeout' => 0]]);
    }
    $this->beforeApplicationDestroyed(static function () use ($file, $default): void {
        foreach (['ownership-holder', 'attachment-contender'] as $name) {
            $db = DB::connection($name);
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            DB::purge($name);
        }
        DB::setDefaultConnection($default);
        foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    });
    DB::setDefaultConnection('ownership-holder');
});

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

it('serializes a real attach between the ownership check and owner writes, including a rolled-back claim', function (bool $rollback): void {
    Http::preventStrayRequests();
    ShellProcess::preventStrayProcesses();
    Queue::fake();
    $holder = DB::connection('ownership-holder');
    $contender = DB::connection('attachment-contender');
    expect($holder->getPdo())->not->toBe($contender->getPdo());
    expect($holder->getConfig('transaction_mode'))->toBe('IMMEDIATE');
    expect($contender->getConfig('transaction_mode'))->toBe('IMMEDIATE');
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    $node->forceFill(['instance_bridge' => '10.253.0.0/24'])->save();
    $project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'type' => ProjectType::NodePackage,
        'repository_url' => 'https://github.com/nckrtl/app.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/home/orbit/apps/app/default', 'status' => InstanceState::Active]);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => AiRouterProcess::NAME,
        'runtime' => 'docker', 'runtime_config' => ['image' => 'axllent/mailpit', 'ports' => ['0.0.0.0:1025:1025/tcp']],
        'desired_state' => 'running', 'status' => 'active']);
    $attach = static fn () => app(AttachInstanceNodeServiceTargetAction::class)->execute($instance, $process->id, 'tcp', 1025, 'MAIL_HOST', 'MAIL_PORT');
    $attempted = false;
    $attempt = null;
    $claimLevel = null;
    DB::listen(static function (QueryExecuted $query) use (&$attempted, &$attempt, &$claimLevel, $attach, $holder, $rollback): void {
        if ($query->connectionName !== 'ownership-holder') {
            return;
        }
        if (! $attempted && str_contains($query->sql, 'from "instance_node_service_targets"')) {
            $attempted = true;
            $claimLevel = $holder->transactionLevel();
            DB::setDefaultConnection('attachment-contender');
            try {
                $attach();
                $attempt = 'committed';
            } catch (PDOException $exception) {
                $attempt = $exception->getMessage();
            } finally {
                DB::setDefaultConnection('ownership-holder');
            }
        }
        if ($rollback && str_starts_with($query->sql, 'insert into "settings"') && in_array(AiRouterSettings::ControlToken, $query->bindings, true)) {
            throw new RuntimeException('Fixture interrupted the final owner setting write.');
        }
    });
    $state = app(AiRouterState::class);

    try {
        $state->enable($node->id, 'cache', 'https://router.example.com', 'fixture-key', 'fixture-read', 'fixture-control');
        expect($rollback)->toBeFalse();
    } catch (RuntimeException $exception) {
        expect($rollback)->toBeTrue();
        expect($exception->getMessage())->toBe('Fixture interrupted the final owner setting write.');
    }

    // A failed immediate begin is the contention barrier: no scheduler timing or SQLite busy wait can admit the attach.
    expect($attempted)->toBeTrue();
    expect($attempt)->toContain('locked');
    expect($claimLevel)->toBe(1);
    expect($holder->transactionLevel())->toBe(0);
    expect($contender->transactionLevel())->toBe(0);
    expect($contender->table('instance_node_service_targets')->count())->toBe(0);
    DB::setDefaultConnection('attachment-contender');
    if ($rollback) {
        expect($state->enabled())->toBeFalse();
        expect($contender->table('settings')->where('key', 'like', 'ai_router.%')->count())->toBe(0);
        expect($attach()->changed)->toBeTrue();
        expect($contender->table('instance_node_service_targets')->count())->toBe(1);
    } else {
        expect($state->enabled())->toBeTrue();
        expect($state->nodeId())->toBe($node->id);
        expect($state->cacheConnection())->toBe('cache');
        expect($state->routerUrl())->toBe('https://router.example.com');
        expect($state->managementKey())->toBe('fixture-key');
        expect($state->readToken())->toBe('fixture-read');
        expect($state->controlToken())->toBe('fixture-control');
        try {
            $attach();
            test()->fail('An attached service cannot be admitted after ownership commits.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('instance.node_service_process_owned');
        }
        expect($contender->table('instance_node_service_targets')->count())->toBe(0);
    }
    Http::assertNothingSent();
    ShellProcess::assertNothingRan();
    Queue::assertNothingPushed();
})->with(['commit' => [false], 'rollback' => [true]]);
