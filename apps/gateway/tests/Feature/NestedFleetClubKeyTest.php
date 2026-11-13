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
    config(['swamp.proof_club_api_key' => FakeProofClubKey]);
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

    it('records why a clone\'s fleet did not start as the failure detail, redacted, and changes no other failure', function (): void {
        SwampNodeFixture::ensure();
        $node = clubSwitchedOnNode();
        $fleet = containerCreate(clubProject('trial', true), $node);
        $normal = containerCreate(clubProject('plain', false), $node);
        $native = ['failed_step' => 'clone-fleet', 'detail' => 'Command exited with code 2', 'stderr_recorded' => false];
        $runs = [[$fleet, ['failed_step' => 'fleet', 'error_code' => 'instance.container_fleet_resume_failed',
            'detail' => "Check Doctor: Readiness check 'doctor' failed after 300 s: HTTP 200: {} password=hunter2-secret-value"]],
            [$normal, ['failed_step' => 'workspace', 'error_code' => 'instance.create_workspace_failed']]];
        foreach ($runs as [$admitted, $result]) {
            $admitted->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();
            app(DevelopmentInstanceCreateProjection::class)->apply($admitted->refresh(), ['status' => 'failed', 'run_id' => 'run-1',
                'error_code' => $result['error_code'], 'failure' => [...$native, 'failed_step' => $result['failed_step'] === 'fleet' ? 'clone-fleet' : 'clone-workspace'],
                'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
                'result' => ['ready' => false, 'instance_id' => $admitted->target_id, ...$result]]);
        }
        $failure = $fleet->refresh()->state['native_failure'];
        expect($fleet->error_code)->toBe('instance.container_fleet_resume_failed')
            ->and($fleet->result)->not->toHaveKey('detail')
            ->and([$failure['failed_step'], $failure['stderr_recorded']])->toBe(['clone-fleet', true])
            ->and($failure['detail'])->toStartWith("Check Doctor: Readiness check 'doctor' failed after 300 s")
            ->and($failure['detail'])->not->toContain('hunter2-secret-value')
            // Any other failed create keeps the native step's failure as it was.
            ->and($normal->refresh()->state['native_failure'])->toBe([...$native, 'failed_step' => 'clone-workspace']);
    });

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
        'refused' => [['error_code' => 'instance.nested_club_key_unsafe_transfer'], 'failed', ['Start the container'], 'refused'],
    ]);
});
