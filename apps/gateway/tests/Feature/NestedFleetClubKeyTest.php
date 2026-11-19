<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Instances\Deployment\ContainerDeployOutcome;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\NestedFleetClubKey;
use App\Domain\Operations\DirectOperation;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/Domain/Instances/ContainerInstanceHelpers.php';

const FakeNestedClubKey = 'fake-nested-club-key-0123456789';
const FakeProofClubKey = 'fake-proof-club-key-0123456789';

beforeEach(function (): void {
    Queue::fake();
    Cache::flush();
    $this->withoutMiddleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class]);
    $this->clubEnvironment = clubEnvironment();
});

/** A shared Gateway env file without the key, as a release reads it; the layout and lock point at it. */
function clubEnvironment(): string
{
    $base = sys_get_temp_dir().'/orbit-club-env-'.bin2hex(random_bytes(4));
    mkdir($base.'/shared', 0700, true);
    $path = $base.'/shared/gateway.env';
    file_put_contents($path, "APP_NAME=Orbit\nORBIT_SWAMP_PROOF_CLUB_API_KEY=".FakeProofClubKey."\n");
    chmod($path, 0600);
    app()->instance(GatewayReleaseLayout::class, new GatewayReleaseLayout($base.'/current/apps/gateway'));
    app()->instance(GatewayReleaseLock::class, new GatewayReleaseLock($base.'/release.lock'));

    return $path;
}

/** Place the key in the shared env file, or take it out, as the installer would; the configuration stays as it is. */
function placeClubKey(string $path, ?string $value): void
{
    $lines = array_filter(explode("\n", (string) file_get_contents($path)), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'ORBIT_NESTED_FLEET_CLUB_API_KEY='));
    file_put_contents($path, implode("\n", [...$lines, ...($value === null ? [] : ['ORBIT_NESTED_FLEET_CLUB_API_KEY='.$value])])."\n");
}

function clubSwitchedOnNode(): Node
{
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);

    return $node->refresh();
}

function clubProject(string $slug, bool $nested): Project
{
    Http::fake(['api.github.com/repos/nckrtl/'.$slug => Http::response(['full_name' => 'nckrtl/'.$slug, 'private' => false])]);

    return Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package', 'repository_url' => 'https://github.com/nckrtl/'.$slug.'.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now(), 'nested_fleet' => $nested]);
}

/** A Swamp vault that acknowledges each put; returns the frames the Gateway sent. @return ArrayObject<int, array<string, mixed>> */
function clubVault(): ArrayObject
{
    $sent = new ArrayObject;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame) use ($sent): void {
        $sent->append($frame);
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use ($sent): array {
        $frame = $sent[count($sent) - 1];

        return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
            'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'fixture']);

    return $sent;
}

describe('the intent and the run inputs', function (): void {
    it('gives only a recorded nested container the delivery description', function (): void {
        $node = clubSwitchedOnNode();
        $containers = app(InstanceContainer::class);
        $nested = Instance::query()->create(['project_id' => clubProject('trial', true)->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/trial']);
        $intent = $containers->intent($nested, $containers->reserve($nested, $node));
        $normal = Instance::query()->create(['project_id' => clubProject('plain', false)->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/plain']);
        $normalIntent = $containers->intent($normal, $containers->reserve($normal, $node));
        expect($intent['club_key'])->toBe(['directory' => '/run/orbit-club', 'file' => 'swamp-club-api-key', 'owner' => 1000, 'group' => 1000,
            'tmpfiles' => '/etc/tmpfiles.d/orbit-club.conf', 'gateway' => 'nested-gateway', 'serve_unit' => 'orbit-swamp-serve'])
            ->and($normalIntent)->not->toHaveKey('club_key');
    });

    it('copies the nested-fleet key, never the proof key, to its one vault entry and marks the container', function (): void {
        $sent = clubVault();
        placeClubKey($this->clubEnvironment, FakeNestedClubKey);
        $run = NestedFleetClubKey::forRun(['project' => 'orbit-instances-nested', 'club_key' => NestedFleetClubKey::intent()]);
        expect($run['container']['club_key']['available'])->toBeTrue()
            ->and($run['copies'])->toBe(['club_key' => ['vault' => 'orbit-control-inputs', 'key' => 'nested-fleet-club-api-key']])
            ->and(count($sent))->toBe(1)
            ->and($sent[0]['payload'])->toBe(['vaultName' => 'orbit-control-inputs', 'key' => 'nested-fleet-club-api-key', 'value' => FakeNestedClubKey, 'force' => true])
            ->and(json_encode($run, JSON_THROW_ON_ERROR))->not->toContain(FakeNestedClubKey);
    });

    it('starts a nested container without the key when the Gateway has none, and leaves every other container untouched', function (): void {
        $sent = clubVault();
        $nested = ['project' => 'orbit-instances-nested', 'club_key' => NestedFleetClubKey::intent()];
        $missing = NestedFleetClubKey::forRun($nested);
        $normal = ['project' => 'orbit-instances', 'name' => 'instance-7'];
        placeClubKey($this->clubEnvironment, FakeNestedClubKey);
        expect($missing['container']['club_key']['available'])->toBeFalse()
            ->and($missing['copies'])->toBe([])
            ->and(NestedFleetClubKey::forRun($normal))->toBe(['container' => $normal, 'copies' => []])
            ->and(NestedFleetClubKey::forRun(null))->toBe(['container' => null, 'copies' => []])
            ->and(count($sent))->toBe(0);
    });
});

describe('a create', function (): void {
    it('reads the key once, at the run start, from the env file: admission reads nothing and a placed key needs no reload', function (): void {
        SwampNodeFixture::ensure();
        clubVault();
        $node = clubSwitchedOnNode();
        // Admitted without a key: the Operation says nothing yet, and a key placed afterwards reaches this run.
        $placedLater = containerCreate(clubProject('trial', true), $node);
        placeClubKey($this->clubEnvironment, FakeNestedClubKey);
        $later = app(DirectOperation::class)->workflowInputs($placedLater);
        // Admitted with a key that was taken out before the start: the run has neither the flag nor the copy.
        $removedLater = containerCreate(clubProject('other', true), $node);
        placeClubKey($this->clubEnvironment, null);
        $removed = app(DirectOperation::class)->workflowInputs($removedLater);
        expect($placedLater->input)->not->toHaveKey('nested_fleet_club_key')
            ->and($removedLater->input)->not->toHaveKey('nested_fleet_club_key')
            ->and($later['intent']['container']['club_key']['available'])->toBeTrue()
            ->and($later['execution_secrets']['club_key'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'nested-fleet-club-api-key'])
            ->and($removed['intent']['container']['club_key']['available'])->toBeFalse()
            ->and($removed['execution_secrets'])->not->toHaveKey('club_key')
            ->and(config()->has('swamp.nested_fleet_club_api_key'))->toBeFalse()
            ->and(json_encode(Operation::query()->get()->toArray(), JSON_THROW_ON_ERROR))->not->toContain(FakeNestedClubKey);
    });

    it('hands a nested-fleet create run its vault reference and flag, and a normal create run neither', function (): void {
        SwampNodeFixture::ensure();
        $sent = clubVault();
        placeClubKey($this->clubEnvironment, FakeNestedClubKey);
        $node = clubSwitchedOnNode();
        $nested = app(DirectOperation::class)->workflowInputs(containerCreate(clubProject('trial', true), $node));
        $normal = app(DirectOperation::class)->workflowInputs(containerCreate(clubProject('plain', false), $node));
        expect($nested['execution_secrets']['club_key'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'nested-fleet-club-api-key'])
            ->and($nested['intent']['container']['club_key']['available'])->toBeTrue()
            ->and($normal['execution_secrets'])->not->toHaveKey('club_key')
            ->and($normal['intent']['container'])->not->toHaveKey('club_key')
            ->and(json_encode([$nested, $normal], JSON_THROW_ON_ERROR))->not->toContain(FakeNestedClubKey)
            ->and(array_filter((array) $sent, fn (array $frame): bool => $frame['payload']['value'] === FakeProofClubKey))->toBe([]);
    });
});

describe('the stdin installer', function (): void {
    function installClubKey(string $stdin): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $stdin);
        rewind($stream);
        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;
        // A streamed input, as a pipe gives it; Artisan::call would read the real stdin.
        $command = Artisan::all()['gateway:nested-fleet-club-key:install'];
        $command->setLaravel(app());
        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
    }

    it('adds the key from stdin, keeps every other setting and prints no value', function (): void {
        $path = $this->clubEnvironment;
        [$code, $printed] = installClubKey(FakeNestedClubKey."\n");
        $environment = (string) file_get_contents($path);
        expect($code)->toBe(0)
            ->and($environment)->toBe("APP_NAME=Orbit\nORBIT_SWAMP_PROOF_CLUB_API_KEY=".FakeProofClubKey."\nORBIT_NESTED_FLEET_CLUB_API_KEY=".FakeNestedClubKey."\n")
            ->and(fileperms($path) & 0777)->toBe(0600)
            ->and($printed)->not->toContain(FakeNestedClubKey);
        // A second install replaces the line.
        [$code] = installClubKey('fake-nested-club-key-rotated-99');
        expect($code)->toBe(0)
            ->and((string) file_get_contents($path))->toContain("ORBIT_NESTED_FLEET_CLUB_API_KEY=fake-nested-club-key-rotated-99\n")
            ->and(substr_count((string) file_get_contents($path), 'ORBIT_NESTED_FLEET_CLUB_API_KEY='))->toBe(1);
    });

    it('publishes a rotated key to the mounted directory before the next guest run', function (): void {
        $host = $this->clubEnvironment;
        $projection = dirname($host).'/.gateway-release-environment';
        mkdir($projection, 0700);
        link($host, $projection.'/gateway.env');
        $guest = new GatewayReleaseLayout(dirname($host, 2).'/guest/current/apps/gateway');
        mkdir($guest->sharedPath(), 0700, true);
        symlink($projection.'/gateway.env', $guest->environmentPath());
        $reader = new SwampReaderEnvironment($guest, $projection);

        [$code, $output] = installClubKey(FakeNestedClubKey);
        expect($code)->toBe(0)->and($output)->not->toContain(FakeNestedClubKey);
        app()->instance(GatewayReleaseLayout::class, $guest);
        app()->instance(SwampReaderEnvironment::class, $reader);
        $sent = clubVault();
        $run = NestedFleetClubKey::forRun(['club_key' => NestedFleetClubKey::intent()]);
        expect($run['container']['club_key']['available'])->toBeTrue()
            ->and($sent[0]['payload']['value'])->toBe(FakeNestedClubKey)
            ->and(scandir($projection))->toBe(['.', '..', 'gateway.env'])
            ->and(fileperms($projection.'/gateway.env') & 07777)->toBe(0600);
        [$code] = installClubKey('fake-guest-install-must-fail');
        expect($code)->toBe(1)->and(is_link($guest->environmentPath()))->toBeTrue()
            ->and(scandir($guest->sharedPath()))->toBe(['.', '..', 'gateway.env']);
    });

    it('refuses unsafe projection files, directories and links', function (): void {
        $host = $this->clubEnvironment;
        $projection = dirname($host).'/.gateway-release-environment';
        mkdir($projection, 0700);
        $guest = new GatewayReleaseLayout(dirname($host, 2).'/guest/current/apps/gateway');
        mkdir($guest->sharedPath(), 0700, true);
        $reader = new SwampReaderEnvironment($guest, $projection);
        symlink($host, $guest->environmentPath());
        expect(fn () => $reader->contents())->toThrow(RuntimeException::class);
        unlink($guest->environmentPath());
        symlink($projection.'/gateway.env', $guest->environmentPath());
        symlink($host, $projection.'/gateway.env');
        expect(fn () => $reader->contents())->toThrow(RuntimeException::class);
        unlink($projection.'/gateway.env');
        copy($host, $projection.'/gateway.env');
        chmod($projection.'/gateway.env', 0644);
        expect(fn () => $reader->contents())->toThrow(RuntimeException::class);
        chmod($projection.'/gateway.env', 0600);
        chmod($projection, 0755);
        expect(fn () => $reader->contents())->toThrow(RuntimeException::class);
        [$code] = installClubKey(FakeNestedClubKey);
        expect($code)->toBe(1)->and(file_get_contents($host))->not->toContain(FakeNestedClubKey);
    });

    it('keeps the previous host settings if the serving projection cannot be replaced', function (): void {
        $host = $this->clubEnvironment;
        $original = file_get_contents($host);
        $projection = dirname($host).'/.gateway-release-environment';
        mkdir($projection, 0700);
        mkdir($projection.'/gateway.env', 0700);

        [$code, $output] = installClubKey(FakeNestedClubKey);
        expect($code)->toBe(1)->and($output)->not->toContain(FakeNestedClubKey)
            ->and(file_get_contents($host))->toBe($original)
            ->and(fileperms($host) & 07777)->toBe(0600)
            ->and(glob(dirname($host).'/.gateway.env.swamp-*'))->toBe([]);
    });

    it('refuses an empty, short or multi-word value and changes nothing', function (string $stdin): void {
        $path = $this->clubEnvironment;
        $before = (string) file_get_contents($path);
        [$code, $printed] = installClubKey($stdin);
        expect($code)->toBe(1)
            ->and((string) file_get_contents($path))->toBe($before)
            ->and($printed)->not->toContain('fake-nested');
    })->with(['', 'short', "fake-nested key with spaces\n", "fake-nested-one\nfake-nested-two\n"]);
});

describe('the result of a start', function (): void {
    it('maps what the run did to the one public word', function (mixed $word, ?string $code, string $outcome): void {
        expect(NestedFleetClubKey::outcome($word, $code))->toBe($outcome);
    })->with([
        'written' => ['changed', null, 'written'],
        'written while the inner Incus was not ready' => ['changed_serve_pending', null, 'written_serve_not_restarted'],
        'unchanged' => ['unchanged', null, 'unchanged'],
        'written, then a later phase failed' => ['changed', 'instance.create_seed_failed', 'written'],
        'nothing written' => [null, null, 'missing'],
        'a word the script never prints' => ['delivered', null, 'missing'],
        'a failed write' => [null, 'instance.nested_club_key_delivery_failed', 'missing'],
        'refused transfer' => [null, 'instance.nested_club_key_unsafe_transfer', 'refused'],
        'refused /run' => [null, 'instance.nested_club_key_not_tmpfs', 'refused'],
    ]);

    it('reports on a create what the run did, never what admission read', function (array $result, string $status, string $outcome): void {
        SwampNodeFixture::ensure();
        // The Gateway held a key when it admitted the create; only the run's word counts.
        placeClubKey($this->clubEnvironment, FakeNestedClubKey);
        $node = clubSwitchedOnNode();
        $operation = containerCreate(clubProject('trial', true), $node);
        $normal = containerCreate(clubProject('plain', false), $node);
        foreach ([$operation, $normal] as $admitted) {
            $admitted->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();
            app(DevelopmentInstanceCreateProjection::class)->apply($admitted->refresh(), ['status' => $status, 'run_id' => 'run-1',
                ...($status === 'failed' ? ['error_code' => $result['error_code']] : []),
                'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
                'result' => ['ready' => $status === 'succeeded', 'instance_id' => $admitted->target_id, 'commit' => str_repeat('d', 40), ...$result]]);
        }
        expect($operation->refresh()->result['nested_fleet_club_key'])->toBe($outcome)
            ->and($operation->result)->not->toHaveKey('club_key')
            ->and($normal->refresh()->result)->not->toHaveKey('nested_fleet_club_key')
            ->and($normal->result)->not->toHaveKey('club_key');
    })->with([
        'written' => [['club_key' => 'changed'], 'succeeded', 'written'],
        'unchanged' => [['club_key' => 'unchanged'], 'succeeded', 'unchanged'],
        'the run delivered nothing' => [[], 'succeeded', 'missing'],
        'refused' => [['failed_step' => 'container', 'error_code' => 'instance.nested_club_key_unsafe_transfer'], 'failed', 'refused'],
    ]);

    it('reports on a deployment what its start did', function (?array $file, string $status, array $failed, string $outcome): void {
        SwampNodeFixture::ensure();
        placeClubKey($this->clubEnvironment, FakeNestedClubKey);
        $instance = Instance::query()->findOrFail(containerCreate(clubProject('trial', true), clubSwitchedOnNode())->target_id);
        $deploy = static function (bool $nested) use ($instance, $file, $status, $failed): Operation {
            $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
                'status' => 'running', 'state' => ['swamp_run_id' => 'run-1'], 'input' => ['deployment' => ['variant' => 'container', 'home' => '/srv/trial',
                    'container' => ['processes' => [], ...($nested ? ['club_key' => NestedFleetClubKey::intent()] : [])]]]]);
            app(InstanceDeployProjection::class)->apply($operation, ['status' => $status, 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(), 'result' => ['steps' => $file === null ? [] : [ContainerDeployOutcome::ResultStep => ['stdout' => json_encode($file, JSON_THROW_ON_ERROR)]],
                    'summary' => ['failed_steps' => $failed]]]);

            return $operation->refresh();
        };
        $nested = $deploy(true);
        $normal = $deploy(false);
        expect($nested->result['nested_fleet_club_key'])->toBe($outcome)
            ->and($normal->result)->not->toHaveKey('nested_fleet_club_key');
    })->with([
        'written' => [['commit' => str_repeat('c', 40), 'club_key' => 'changed'], 'succeeded', [], 'written'],
        'written, serve not restarted' => [['commit' => str_repeat('c', 40), 'club_key' => 'changed_serve_pending'], 'succeeded', [], 'written_serve_not_restarted'],
        'unchanged' => [['commit' => str_repeat('c', 40), 'club_key' => 'unchanged'], 'succeeded', [], 'unchanged'],
        'the run delivered nothing' => [['commit' => str_repeat('c', 40)], 'succeeded', [], 'missing'],
        'refused' => [['error_code' => 'instance.nested_club_key_unsafe_transfer', 'failed_phase' => 'start'], 'failed', ['Prepare the container'], 'refused'],
    ]);

    it('reports on a refresh what each part did, and nothing for a run without a refresh', function (): void {
        SwampNodeFixture::ensure();
        placeClubKey($this->clubEnvironment, FakeNestedClubKey);
        $instance = Instance::query()->findOrFail(containerCreate(clubProject('trial', true), clubSwitchedOnNode())->target_id);
        $refresh = ['migrate' => 'nothing', 'restart' => 'yes', 'converge' => 'ran',
            'converge_reasons' => ['gateway' => ['reason' => 'unchanged'], 'app-dev' => ['reason' => 'digest_unreadable', 'error' => 'RuntimeException: The Node has no Instance bridge yet.'],
                'app-prod' => ['reason' => 'digest_changed', 'phases' => ['software', 'roles']]],
            'converge_nodes' => ['app-dev', 'app-prod'], 'changed' => 0, 'tokens' => 'reminted', 'tokens_reason' => 'incomplete',
            'base' => 'kept', 'base_containers' => [], 'record' => 'present', 'tools' => 'built', 'tools_reason' => 'missing',
            'shrink' => 'ran', 'shrink_reason' => 'packages', 'swamp' => 'installed', 'swamp_reason' => 'changed', 'swamp_version' => '20261114.210101.0-sha.537e03d1',
            'seconds' => ['gateway' => 14, 'converge' => 70, 'ready' => 3, 'base' => 1, 'tools' => 34]];
        $deploy = static function (array $file) use ($instance): Operation {
            $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $instance->node_id,
                'status' => 'running', 'state' => ['swamp_run_id' => 'run-1'], 'input' => ['deployment' => ['variant' => 'container', 'home' => '/srv/trial',
                    'container' => ['processes' => [], 'club_key' => NestedFleetClubKey::intent()]]]]);
            app(InstanceDeployProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(), 'result' => ['steps' => [ContainerDeployOutcome::ResultStep => ['stdout' => json_encode($file, JSON_THROW_ON_ERROR)]],
                    'summary' => ['failed_steps' => []]]]);

            return $operation->refresh();
        };
        $commit = str_repeat('c', 40);

        expect($deploy(['commit' => $commit, 'club_key' => 'unchanged', 'fleet_refresh' => $refresh])->result['fleet_refresh'])->toBe($refresh)
            ->and($deploy(['commit' => $commit, 'club_key' => 'unchanged'])->result)->not->toHaveKey('fleet_refresh')
            // Words the refresh does not use, or a count that is no count, are not recorded.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'base' => 'maybe']])->result)->not->toHaveKey('fleet_refresh')
            // Why each Node converged or not is one of the refresh's own words; only an unreadable digest names an error, and only a
            // converged Node names the phases its converge ran (docs/reference/development-containers.md#what-a-refresh-skips).
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_reasons' => [...$refresh['converge_reasons'], 'gateway' => ['reason' => 'because']]]])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_reasons' => [...$refresh['converge_reasons'], 'app-dev' => ['reason' => 'digest_unreadable']]]])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_reasons' => [...$refresh['converge_reasons'], 'gateway' => ['reason' => 'unchanged', 'phases' => ['roles']]]]])->result)->not->toHaveKey('fleet_refresh')
            // `unchanged` means the Node did not converge: a converged Node never says it, and a Node that did not converge says nothing else.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_reasons' => [...$refresh['converge_reasons'], 'app-prod' => ['reason' => 'unchanged']]]])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_reasons' => [...$refresh['converge_reasons'], 'gateway' => ['reason' => 'stale']]]])->result)->not->toHaveKey('fleet_refresh')
            // Only nested-app-dev converges for its Instance host part.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_reasons' => [...$refresh['converge_reasons'], 'app-dev' => ['reason' => 'host_due', 'phases' => ['roles']]]]])->result['fleet_refresh']['converge_reasons']['app-dev'])->toBe(['reason' => 'host_due', 'phases' => ['roles']])
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_nodes' => ['gateway', 'app-dev', 'app-prod'], 'converge_reasons' => [...$refresh['converge_reasons'], 'gateway' => ['reason' => 'host_due']]]])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_reasons' => [...$refresh['converge_reasons'], 'app-prod' => ['reason' => 'digest_changed', 'phases' => ['check']]]]])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => array_diff_key($refresh, ['converge_reasons' => true])])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'changed' => '3']])->result)->not->toHaveKey('fleet_refresh')
            // The converged Nodes are the inner fleet's own role names, each once; a refresh that does not name them is not recorded.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_nodes' => ['app-dev', 'app-dev']]])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_nodes' => ['beast']]])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_nodes' => [['app-dev']]]])->result)->not->toHaveKey('fleet_refresh')
            // A converge that ran names its Nodes; a skipped one names none.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge_nodes' => []]])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'converge' => 'skipped']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => array_diff_key($refresh, ['converge_nodes' => true])])->result)->not->toHaveKey('fleet_refresh')
            // What the refresh did with the nested Gateway's Swamp tokens, and why, are its own words too.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'tokens' => 'renewed']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'tokens_reason' => 'expired']])->result)->not->toHaveKey('fleet_refresh')
            // A refresh whose record vouches for the oldest token reads no list: young, because `recorded`.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'tokens' => 'young', 'tokens_reason' => 'recorded']])->result['fleet_refresh'])
            ->toBe([...$refresh, 'tokens' => 'young', 'tokens_reason' => 'recorded'])
            // So are what it did with the pinned shape tools and why; a refresh that does not say is not recorded.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'tools' => 'skipped']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'tools_reason' => 'pip']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => array_diff_key($refresh, ['tools' => true])])->result)->not->toHaveKey('fleet_refresh')
            // So are what it did with the nested root disks' shrink and why.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'shrink' => 'shrunk']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'shrink_reason' => 'size']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => array_diff_key($refresh, ['shrink' => true])])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'shrink' => 'skipped', 'shrink_reason' => 'current']])->result['fleet_refresh'])
            ->toBe([...$refresh, 'shrink' => 'skipped', 'shrink_reason' => 'current'])
            // So are what it did with the nested Gateway's Swamp, why, and the release it read; a refresh that does not say is not recorded.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'swamp' => 'upgraded']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'swamp_reason' => 'newer']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'swamp_version' => 'latest']])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => array_diff_key($refresh, ['swamp_version' => true])])->result)->not->toHaveKey('fleet_refresh')
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'swamp' => 'skipped', 'swamp_reason' => 'no_key', 'swamp_version' => null]])->result['fleet_refresh'])
            ->toBe([...$refresh, 'swamp' => 'skipped', 'swamp_reason' => 'no_key', 'swamp_version' => null])
            // Unknown parts of `seconds` are dropped.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'seconds' => ['gateway' => 2, 'secret' => 'x']]])->result['fleet_refresh']['seconds'])
            ->toBe(['gateway' => 2]);

        // The Instance host's word is kept, before `seconds`; a run of an older Ansible names none and is recorded as before.
        $withHost = static fn (string $host): array => [...array_diff_key($refresh, ['seconds' => true]), 'host' => $host, 'seconds' => $refresh['seconds']];
        foreach (['skipped_idmap_small', 'converged', 'kept'] as $host) {
            expect($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'host' => $host]])->result['fleet_refresh'])->toBe($withHost($host));
        }
        // A host word not these is left out, and the rest of the refresh is still recorded.
        expect($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'host' => 'built']])->result['fleet_refresh'])->toBe($refresh)
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'host' => null]])->result['fleet_refresh'])->toBe($refresh)
            // The host's time is not a part of `seconds`.
            ->and($deploy(['commit' => $commit, 'fleet_refresh' => [...$refresh, 'host' => 'kept', 'seconds' => ['gateway' => 2, 'host' => 5]]])->result['fleet_refresh']['seconds'])
            ->toBe(['gateway' => 2]);
    });
});
