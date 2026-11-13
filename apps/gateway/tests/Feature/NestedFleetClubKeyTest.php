<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
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
    config(['swamp.proof_club_api_key' => FakeProofClubKey, 'swamp.nested_fleet_club_api_key' => '']);
});

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
        config(['swamp.nested_fleet_club_api_key' => FakeNestedClubKey]);
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
        config(['swamp.nested_fleet_club_api_key' => FakeNestedClubKey]);
        expect($missing['container']['club_key']['available'])->toBeFalse()
            ->and($missing['copies'])->toBe([])
            ->and(NestedFleetClubKey::forRun($normal))->toBe(['container' => $normal, 'copies' => []])
            ->and(NestedFleetClubKey::forRun(null))->toBe(['container' => null, 'copies' => []])
            ->and(count($sent))->toBe(0);
    });
});

describe('a create', function (): void {
    it('says on the Operation whether a nested-fleet start gets the key, and a normal create says nothing', function (string $key): void {
        SwampNodeFixture::ensure();
        config(['swamp.nested_fleet_club_api_key' => $key]);
        $node = clubSwitchedOnNode();
        $nested = containerCreate(clubProject('trial', true), $node);
        $normal = containerCreate(clubProject('plain', false), $node);
        expect($nested->input['nested_fleet_club_key'])->toBe($key === '' ? 'missing' : 'delivered')
            ->and(Operation::query()->findOrFail($nested->id)->input)->not->toHaveKey('club')
            ->and($normal->input)->not->toHaveKey('nested_fleet_club_key')
            ->and(json_encode(Operation::query()->get()->toArray(), JSON_THROW_ON_ERROR))->not->toContain(FakeNestedClubKey);
    })->with(['', FakeNestedClubKey]);

    it('hands a nested-fleet create run its vault reference and flag, and a normal create run neither', function (): void {
        SwampNodeFixture::ensure();
        $sent = clubVault();
        config(['swamp.nested_fleet_club_api_key' => FakeNestedClubKey]);
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
        $path = clubEnvironment();
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
        $path = clubEnvironment();
        $before = (string) file_get_contents($path);
        [$code, $printed] = installClubKey($stdin);
        expect($code)->toBe(1)
            ->and((string) file_get_contents($path))->toBe($before)
            ->and($printed)->not->toContain('fake-nested');
    })->with(['', 'short', "fake-nested key with spaces\n", "fake-nested-one\nfake-nested-two\n"]);
});
