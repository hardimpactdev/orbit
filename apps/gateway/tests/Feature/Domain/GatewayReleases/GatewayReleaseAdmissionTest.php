<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\AdmitSwampGatewayReleaseAction;
use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\GatewayRelease;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

const ReleaseA = 'aaaaaaaaaaaa0000000000000000000000000000';
const ReleaseB = 'bbbbbbbbbbbb0000000000000000000000000000';
const ReleaseC = 'cccccccccccc0000000000000000000000000000';

beforeEach(function (): void {
    // A release layout with releases A and B sealed and A current, and the shared repository's GitHub origin.
    $this->base = sys_get_temp_dir().'/orbit-release-admission-'.bin2hex(random_bytes(4));
    foreach ([ReleaseA, ReleaseB] as $sha) {
        mkdir($this->base.'/releases/'.substr($sha, 0, 12).'/apps/gateway', 0700, true);
        file_put_contents($this->base.'/releases/'.substr($sha, 0, 12).'/REVISION', $sha."\n");
    }
    symlink('releases/'.substr(ReleaseA, 0, 12), $this->base.'/orbit');
    mkdir($this->base.'/shared', 0700, true);
    Process::run(['git', 'init', '--quiet', '--bare', $this->base.'/shared/orbit.git'])->throw();
    Process::run(['git', '--git-dir', $this->base.'/shared/orbit.git', 'remote', 'add', 'origin', 'https://github.com/hardimpactdev/orbit.git'])->throw();
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway']);
    $gateway = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-host-key', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    Node::withoutEvents(static fn (): Node => Node::query()->forceCreate(['id' => 209, 'name' => 'swamp', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.15',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'swamp-host-key', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']));
    app(GatewayReleaseAutomation::class)->enable();
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

/** An agent delivery that runs `$fetch` instead of reading GitHub. */
function releaseAgent(Closure $fetch): void
{
    app()->instance(GatewayReleaseAgent::class, new readonly class($fetch) implements GatewayReleaseAgent
    {
        public function __construct(private Closure $fetch) {}

        public function fetch(string $revision, Node $gateway): array
        {
            return ($this->fetch)($revision);
        }
    });
}

it('records a refused automatic attempt, so the resolver leaves the commit out', function (): void {
    $excluded = [];
    app()->instance(GreenCommitResolver::class, new class($excluded) implements GreenCommitResolver
    {
        public function __construct(public array &$excluded) {}

        public function resolve(GitHubRepository $repository, string $branch, string $checkName, string $deployedSha, array $failedShas = []): ?GreenCommit
        {
            $this->excluded = $failedShas;

            return in_array(ReleaseC, $failedShas, true) ? null : new GreenCommit(ReleaseC, new GitHubCheckRun('Required checks', 'success', null));
        }
    });
    releaseAgent(static fn (): never => throw new GatewayReleaseException('agent-delivery', 'gateway.release_provenance_invalid', 'No valid provenance.', 422, sha: ReleaseC));

    $first = app(RunAutomaticGatewayReleaseAction::class)->execute();
    $record = GatewayRelease::query()->sole();
    $second = app(RunAutomaticGatewayReleaseAction::class)->execute();

    expect($first)->toMatchArray(['result' => 'failed', 'sha' => ReleaseC, 'record' => $record->id, 'error_code' => 'gateway.release_provenance_invalid'])
        ->and($record->only(['trigger', 'outcome', 'retryable', 'error_code']))->toBe(['trigger' => 'auto', 'outcome' => 'failed', 'retryable' => false, 'error_code' => 'gateway.release_provenance_invalid'])
        ->and($excluded)->toBe([ReleaseC])
        ->and($second['result'])->toBe('up_to_date')
        ->and(GatewayRelease::query()->count())->toBe(1);
});

it('refuses an admission when another release went live during its checks', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', $pem));
    $run = ['id' => 777, 'event' => 'push', 'head_branch' => 'main', 'path' => '.github/workflows/ci.yml', 'head_sha' => ReleaseC,
        'repository' => ['id' => 1, 'full_name' => 'hardimpactdev/orbit'], 'head_repository' => ['id' => 1, 'full_name' => 'hardimpactdev/orbit']];
    Http::fake(fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/installation') => Http::response(['id' => 123]),
        str_ends_with($request->url(), '/access_tokens') => Http::response(['token' => 'actions-token', 'permissions' => ['actions' => 'read', 'metadata' => 'read'],
            'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z')], 201),
        str_contains($request->url(), '/actions/artifacts?') => Http::response(['total_count' => 1, 'artifacts' => [['id' => 4242, 'name' => 'web-dist-'.ReleaseC,
            'size_in_bytes' => 1024, 'digest' => 'sha256:'.str_repeat('0', 64), 'expired' => false, 'created_at' => '2026-11-04T18:00:00Z',
            'workflow_run' => ['id' => 777, 'repository_id' => 1, 'head_repository_id' => 1, 'head_branch' => 'main', 'head_sha' => ReleaseC]]]]),
        str_contains($request->url(), '/actions/runs/777') => Http::response($run),
        default => Http::response('unexpected '.$request->url(), 500),
    });
    // Release B goes live while admission reads the agent delivery of C.
    releaseAgent(function (string $revision): array {
        unlink($this->base.'/orbit');
        symlink('releases/'.substr(ReleaseB, 0, 12), $this->base.'/orbit');

        return ['sha' => $revision, 'manifest' => '{}'];
    });

    expect(fn () => app(AdmitSwampGatewayReleaseAction::class)->execute(ReleaseC))
        ->toThrow(fn (GatewayReleaseException $exception) => expect([$exception->errorCode, $exception->getMessage()])
            ->toBe(['gateway.release_in_progress', 'The current release changed during admission.']))
        ->and(GatewayRelease::query()->count())->toBe(0);
});
