<?php

declare(strict_types=1);

use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Fleet\StaticCliRelease;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

it('lets a second SQLite connection commit an unrelated write during cold catalog resolution and admits the pinned CLI', function (): void {
    Queue::fake();
    $this->travelTo(now('Europe/Amsterdam')->setTime(3, 5));
    $root = sys_get_temp_dir().'/orbit-fleet-catalog-'.bin2hex(random_bytes(8));
    $commit = 'ca7a109'.str_repeat('0', 33);
    $releasePath = $root.'/releases/'.substr($commit, 0, 12);
    mkdir($releasePath.'/apps/gateway', 0700, true);
    $database = $root.'/gateway.sqlite3';
    $default = DB::getDefaultConnection();
    $connections = ['fleet-catalog', 'unrelated-writer'];
    $this->beforeApplicationDestroyed(static function () use ($root, $connections, $default): void {
        foreach ($connections as $name) {
            DB::purge($name);
        }
        DB::setDefaultConnection($default);
        File::deleteDirectory($root);
    });
    expect(Artisan::call('migrate:fresh'))->toBe(0);
    DB::statement('VACUUM INTO ?', [$database]);
    foreach ($connections as $name) {
        config(["database.connections.{$name}" => [...config("database.connections.{$default}"), 'database' => $database, 'busy_timeout' => 0]]);
    }
    DB::setDefaultConnection('fleet-catalog');
    $fleet = DB::connection('fleet-catalog');
    $writer = DB::connection('unrelated-writer');
    expect($fleet->getPdo())->not->toBe($writer->getPdo())
        ->and($fleet->getConfig('transaction_mode'))->toBe('IMMEDIATE');

    file_put_contents($releasePath.'/REVISION', $commit."\n");
    symlink('releases/'.substr($commit, 0, 12), $root.'/orbit');
    $catalogPath = $root.'/cli.json';
    file_put_contents($catalogPath, json_encode(['number' => 9001, 'checksums_url' => 'https://example.test/SHA256SUMS',
        'assets' => [['platform' => 'linux-x86_64', 'url' => 'https://example.test/orbit', 'sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    config(['cache.default' => 'array', 'fleet.rollout' => true, 'app.version' => $commit,
        'orbit.gateway_checkout' => $root.'/orbit/apps/gateway', 'swamp.run_token' => 'test-token']);
    $catalog = new StaticCliRelease($catalogPath);
    app()->instance(ReleaseHistory::class, $catalog);
    app()->instance(FleetServingRelease::class, new FleetServingRelease($releasePath.'/apps/gateway'));
    $lookup = $this->mock(CliReleaseCatalog::class);
    $writeError = null;
    $lookup->shouldReceive('find')->once()->andReturnUsing(static function (string $sha, CliReleaseName $name) use ($writer, $catalog, &$writeError): DesiredCliReleaseData {
        try {
            $writer->transaction(fn () => $writer->table('nodes')->where('name', 'unrelated')->update(['tld' => 'during-lookup.test']));
        } catch (PDOException $failure) {
            $writeError = $failure->getMessage();
            throw $failure;
        }

        return $catalog->find($sha, $name);
    });
    expect(app(DesiredFleetState::class)->cached())->toBeNull();

    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($gateway);
    $runner = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'runner', 'public_ssh_host' => '192.0.2.21',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.21', 'ssh_host_fingerprint' => 'SHA256:runner',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned',
        'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $runner->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
    $unrelated = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'unrelated', 'public_ssh_host' => '192.0.2.22',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $runner->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host()]])->saveQuietly();
    $release = GatewayRelease::query()->create(['sha' => $commit, 'trigger' => 'auto', 'outcome' => 'verified', 'duration_ms' => 0,
        'phases' => ['fleet' => ['status' => 'pending', 'nodes' => []]]]);

    $action = app(ContinueReleaseFleetAction::class);
    expect($action->current()?->id)->toBe($release->id);
    // Evict the cache during admission so a second resolution cannot appear to reuse the preflight pin.
    Event::listen(TransactionBeginning::class, static function (TransactionBeginning $event) use ($commit): void {
        if ($event->connectionName === 'fleet-catalog') {
            Cache::forget('orbit:desired-fleet-state:v1:'.hash('sha256', $commit));
        }
    });
    $delay = $action->execute($release);
    expect($writeError)->toBeNull();
    expect($delay)->toBe(10, json_encode($release->refresh()->phases['fleet'], JSON_THROW_ON_ERROR));
    expect($unrelated->refresh()->tld)->toBe('during-lookup.test');
    $operation = Operation::query()->where('kind', 'fleet.rollout')->sole();
    expect($operation->input['nodes'][0]['node_id'])->toBe($runner->id)
        ->and($operation->input['nodes'][0]['release'])->toBe([
            'version' => '0.9001.0', 'url' => 'https://example.test/orbit', 'sha256' => str_repeat('a', 64),
        ]);
});
