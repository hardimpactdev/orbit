<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\AdmitSwampGatewayReleaseAction;
use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRunProjection;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Jobs\Fleet\AdmitFleetRollout;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

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

it('does not spend an automatic attempt when the serving release pauses during a failed preflight', function (): void {
    $this->mock(GreenCommitResolver::class)->shouldReceive('resolve')->once()
        ->andReturn(new GreenCommit(ReleaseC, new GitHubCheckRun('Required checks', 'success', null)));
    releaseAgent(static function (): never {
        app(GatewayReleaseAutomation::class)->pauseFor('rollback');

        throw new GatewayReleaseException('agent-delivery', 'gateway.release_provenance_invalid', 'Bad provenance.', 422, sha: ReleaseC);
    });

    expect(app(RunAutomaticGatewayReleaseAction::class)->execute())->toMatchArray(['result' => 'busy', 'sha' => ReleaseC])
        ->and(GatewayRelease::query()->count())->toBe(0);
});

it('does not consume an attempt based on retry state that changed during preflight', function (bool $retryable): void {
    $this->mock(GreenCommitResolver::class)->shouldReceive('resolve')->once()
        ->andReturn(new GreenCommit(ReleaseC, new GitHubCheckRun('Required checks', 'success', null)));
    releaseAgent(static function () use ($retryable): never {
        GatewayRelease::query()->create(['sha' => ReleaseC, 'trigger' => 'auto', 'outcome' => 'failed',
            'retryable' => $retryable, 'phases' => [], 'duration_ms' => 0]);

        throw new GatewayReleaseException('agent-delivery', 'gateway.release_provenance_invalid', 'Bad provenance.', 422, sha: ReleaseC);
    });

    expect(app(RunAutomaticGatewayReleaseAction::class)->execute())->toMatchArray(['result' => 'busy', 'sha' => ReleaseC])
        ->and(GatewayRelease::query()->count())->toBe(1);
})->with(['in backoff' => true, 'permanently failed' => false]);

it('requeues the serving cycle after a restart and waits before admitting the latest eligible commit', function (): void {
    Queue::fake();
    app()->instance(FleetServingRelease::class, new FleetServingRelease($this->base.'/releases/'.substr(ReleaseA, 0, 12).'/apps/gateway'));
    $release = GatewayRelease::query()->create(['sha' => ReleaseA, 'trigger' => 'auto', 'outcome' => 'verified',
        'phases' => ['fleet' => ['status' => 'current'], 'default_refresh' => ['status' => 'current']], 'duration_ms' => 0]);
    $this->mock(GreenCommitResolver::class)->shouldReceive('resolve')->twice()
        ->andReturn(new GreenCommit(ReleaseC, new GitHubCheckRun('Required checks', 'success', null)));
    releaseAgent(static fn (): never => throw new GatewayReleaseException('agent-delivery', 'gateway.release_agent_delivery_unavailable', 'Publishing.', 503, sha: ReleaseC));

    expect(app(RunAutomaticGatewayReleaseAction::class)->execute())->toMatchArray([
        'result' => 'waiting_for_release_cycle', 'sha' => ReleaseC, 'record' => $release->id,
    ]);
    Queue::assertPushed(AdmitFleetRollout::class, fn (AdmitFleetRollout $job): bool => $job->releaseId === $release->id);
    expect(GatewayRelease::query()->count())->toBe(1);

    $release->update(['phases' => [...$release->phases, 'seed_publication' => ['status' => 'current']]]);
    expect(app(RunAutomaticGatewayReleaseAction::class)->execute())->toMatchArray([
        'result' => 'waiting_for_agent_delivery', 'sha' => ReleaseC,
    ])->and(GatewayRelease::query()->count())->toBe(1);
});

it('requeues refresh from a verified release with rollout disabled', function (): void {
    Queue::fake();
    app()->instance(FleetServingRelease::class, new FleetServingRelease($this->base.'/releases/'.substr(ReleaseA, 0, 12).'/apps/gateway'));
    $release = GatewayRelease::query()->create(['sha' => ReleaseA, 'trigger' => 'auto', 'outcome' => 'verified',
        'phases' => ['fleet' => ['status' => 'disabled']], 'duration_ms' => 0]);
    $this->mock(GreenCommitResolver::class)->shouldReceive('resolve')->once()->andReturnNull();

    expect(app(RunAutomaticGatewayReleaseAction::class)->execute())->toMatchArray([
        'result' => 'waiting_for_release_cycle', 'sha' => ReleaseA, 'record' => $release->id,
    ]);
    Queue::assertPushed(AdmitFleetRollout::class, fn (AdmitFleetRollout $job): bool => $job->releaseId === $release->id);
});

it('refuses to admit a duplicate automatic operation for the serving commit', function (): void {
    releaseAgent(static fn (): never => throw new LogicException('An already serving revision needs no delivery.'));
    expect(fn () => app(AdmitSwampGatewayReleaseAction::class)->execute(ReleaseA, trigger: 'auto'))
        ->toThrow(fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_in_progress'))
        ->and(GatewayRelease::query()->count())->toBe(0);
});

it('rechecks the serving release and rollback or fleet state before admission', function (string $race): void {
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
    if ($race === 'changed before preflight') {
        unlink($this->base.'/orbit');
        symlink('releases/'.substr(ReleaseB, 0, 12), $this->base.'/orbit');
    }
    releaseAgent(function (string $revision) use ($race): array {
        if ($race === 'changed during preflight') {
            unlink($this->base.'/orbit');
            symlink('releases/'.substr(ReleaseB, 0, 12), $this->base.'/orbit');
        } elseif ($race === 'rollback pause') {
            app(GatewayReleaseAutomation::class)->pauseFor('rollback');
        } elseif ($race === 'cycle started') {
            app()->instance(FleetServingRelease::class, new FleetServingRelease($this->base.'/releases/'.substr(ReleaseA, 0, 12).'/apps/gateway'));
            GatewayRelease::query()->create(['sha' => ReleaseA, 'trigger' => 'deploy', 'outcome' => 'verified',
                'phases' => ['fleet' => ['status' => 'pending']], 'duration_ms' => 0]);
        }

        return ['sha' => $revision, 'manifest' => '{}'];
    });

    $expected = 'The serving release or its automatic release state changed during admission.';
    expect(fn () => app(AdmitSwampGatewayReleaseAction::class)->execute(ReleaseC, trigger: 'auto', expectedServingSha: ReleaseA))
        ->toThrow(fn (GatewayReleaseException $exception) => expect([$exception->errorCode, $exception->getMessage()])
            ->toBe(['gateway.release_in_progress', $expected]))
        ->and(GatewayRelease::query()->where('trigger', 'auto')->count())->toBe(0);
})->with(['changed before preflight', 'changed during preflight', 'rollback pause', 'cycle started']);

it('rolls back to a retained release by releasing its commit, and refuses an unknown or current one', function (): void {
    $fetched = [];
    releaseAgent(static function (string $revision) use (&$fetched): never {
        $fetched[] = $revision;

        throw new GatewayReleaseException('agent-delivery', 'gateway.release_provenance_invalid', 'No valid provenance.', 422, sha: $revision);
    });
    $admit = app(AdmitSwampGatewayReleaseAction::class);

    expect(fn () => $admit->rollback('cccccccccccc'))->toThrow(fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_not_prepared'))
        ->and(fn () => $admit->rollback(substr(ReleaseA, 0, 12)))->toThrow(fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_already_current'))
        ->and(fn () => $admit->rollback(substr(ReleaseB, 0, 12)))->toThrow(fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_provenance_invalid'))
        ->and($fetched)->toBe([ReleaseB]);
});

it('observes the two container releases without a host worktree and detects a former live process', function (): void {
    $layout = GatewayReleaseLayout::fromConfig();
    mkdir(dirname($layout->containerInventoryPath()), 0700);
    file_put_contents($layout->containerInventoryPath(), json_encode(['version' => 1, 'live' => ReleaseB, 'old' => ReleaseA]));
    $inventory = $layout->observe($this->base.'/releases/'.substr(ReleaseA, 0, 12).'/apps/gateway');
    expect($inventory->state)->toBe('adopted')
        ->and($inventory->retained)->toBe([substr(ReleaseB, 0, 12), substr(ReleaseA, 0, 12)])
        ->and($inventory->superseded)->toBeTrue()
        ->and($layout->observe($this->base.'/releases/'.substr(ReleaseB, 0, 12).'/apps/gateway')->superseded)->toBeFalse();
    file_put_contents($layout->containerInventoryPath(), '{"version":1,"live":null,"old":null}');
    expect(fn () => $layout->observe())->toThrow(GatewayReleaseException::class);
});

it('resumes a retained release without minting a GitHub token or selecting a new artifact', function (): void {
    $operation = new Operation(['input' => ['sha' => ReleaseB, 'retained' => true, 'repository' => 'hardimpactdev/orbit']]);
    $input = app(GatewayReleaseRunProjection::class)->workflowInput($operation);

    // The projection yields an empty map; DirectOperation checks it and encodes it as {} for Swamp.
    expect($input['intent'])->toBe($operation->input)
        ->and($input['execution_secrets'])->toBe([]);
    Http::assertNothingSent();
});

it('dispatches adopted containers through the common production workflow and preserves earlier admissions', function (): void {
    $projection = app(GatewayReleaseRunProjection::class);
    expect($projection->workflow(new Operation(['input' => ['target' => 'gateway']])))->toBe('production-release')
        ->and($projection->workflow(new Operation(['input' => []])))->toBe('operation-gateway-release');
});

it('drains the container Horizon fallback only when the Gateway uses its queue', function (): void {
    $node = Node::query()->firstOrFail();
    config(['orbit.gateway_queue.connection' => 'queue']);
    expect(GatewayHorizon::releaseUnit($node, true))->toBe('orbit-gateway-horizon.service')
        ->and(GatewayHorizon::releaseUnit($node, false))->toBe('');
    config(['orbit.gateway_queue.connection' => '']);
    expect(GatewayHorizon::releaseUnit($node, true))->toBe('');
});

it('admits only the exact serving container for reconciliation and uses no GitHub execution copy', function (): void {
    Queue::fake();
    Node::findOrFail(209)->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    $admit = app(AdmitSwampGatewayReleaseAction::class);
    $layout = GatewayReleaseLayout::fromConfig();
    $refused = static fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_reconcile_not_live');
    expect(fn () => $admit->execute(ReleaseA, trigger: 'reconcile'))->toThrow($refused);
    mkdir(dirname($layout->containerInventoryPath()), 0700);
    file_put_contents($layout->containerInventoryPath(), json_encode(['version' => 1, 'live' => ReleaseA, 'old' => ReleaseB]));
    expect(fn () => $admit->execute(ReleaseB, trigger: 'reconcile'))->toThrow($refused)
        ->and(fn () => $admit->execute(substr(ReleaseA, 0, 12).str_repeat('1', 28), trigger: 'reconcile'))->toThrow($refused)
        ->and(fn () => $admit->execute(ReleaseA))->toThrow(fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_already_current'))
        ->and(GatewayRelease::query()->count())->toBe(0);
    symlink(base_path('../../swamp'), $layout->currentPath().'/swamp');
    $record = $admit->execute(ReleaseA, trigger: 'reconcile');
    $operation = Operation::findOrFail($record->phases['run']['operation_id']);
    // The submitted inputs pass DirectOperation's execution-secret check and encode as an empty map.
    $input = app(DirectOperation::class)->workflowInputs($operation);

    expect($record->trigger)->toBe('reconcile')
        ->and(app(DirectOperation::class)->workflowFor($operation))->toBe('operation-gateway-reconcile')
        ->and($input['intent']['sha'])->toBe(ReleaseA)
        ->and($input['intent']['grants'])->toHaveKeys(['grants/orbit-gateway.yaml', 'grants/orbit-serve.yaml'])
        ->and($input['intent'])->not->toHaveKeys(['manifest', 'web', 'repository'])
        ->and(json_encode($input['execution_secrets']))->toBe('{}');
    Http::assertNothingSent();
});

it('admits a container rollback only to the stopped old release and refuses older deploys and force', function (): void {
    Queue::fake();
    Node::findOrFail(209)->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    $layout = GatewayReleaseLayout::fromConfig();
    mkdir(dirname($layout->containerInventoryPath()), 0700, true);
    file_put_contents($layout->containerInventoryPath(), json_encode(['version' => 2, 'live' => ReleaseA, 'old' => ReleaseB, 'host_retired' => true]));
    $admit = app(AdmitSwampGatewayReleaseAction::class);
    $code = fn (string $expected) => fn (GatewayReleaseException $e) => expect($e->errorCode)->toBe($expected);
    expect(fn () => $admit->execute(ReleaseB))->toThrow($code('gateway.release_downgrade'))
        ->and(fn () => $admit->execute(ReleaseC, force: true))->toThrow($code('gateway.release_downgrade'))
        ->and(fn () => $admit->rollback(substr(ReleaseC, 0, 12)))->toThrow($code('gateway.release_not_prepared'))
        ->and(fn () => $admit->rollback(substr(ReleaseA, 0, 12)))->toThrow($code('gateway.release_already_current'))
        ->and(fn () => $admit->execute(ReleaseC, force: true, trigger: 'rollback'))->toThrow($code('gateway.release_not_prepared'))
        ->and(GatewayRelease::query()->count())->toBe(0);

    $record = $admit->rollback(substr(ReleaseB, 0, 12));
    $operation = Operation::findOrFail($record->phases['run']['operation_id']);
    // The submitted inputs pass DirectOperation's execution-secret check and encode as an empty map.
    $input = app(DirectOperation::class)->workflowInputs($operation);

    expect($record->trigger)->toBe('rollback')
        ->and(app(DirectOperation::class)->workflowFor($operation))->toBe('production-release')
        ->and($input['intent'])->toMatchArray(['target' => 'gateway', 'sha' => ReleaseB, 'force' => true, 'retained' => true, 'previous' => substr(ReleaseA, 0, 12)])
        ->and($input['intent'])->not->toHaveKeys(['manifest', 'web', 'repository'])
        ->and(json_encode($input['execution_secrets']))->toBe('{}');
    Http::assertNothingSent();

    // A refused start ran nothing: the release record fails as retryable instead of staying running.
    app(StartSwampRun::class)->refuse($operation, new ResourceOperationException('swamp.start_job_failed', 'Refused before submission.', 503));
    expect($record->refresh()->outcome)->toBe('failed')
        ->and($record->retryable)->toBeTrue()
        ->and($record->error_code)->toBe('swamp.start_job_failed');
});

it('observes the stopped old release that a container Gateway keeps for rollback', function (): void {
    $layout = GatewayReleaseLayout::fromConfig();
    mkdir(dirname($layout->containerInventoryPath()), 0700, true);
    $state = ['version' => 2, 'live' => ReleaseA, 'old' => ReleaseB, 'host_retired' => true];
    file_put_contents($layout->containerInventoryPath(), json_encode($state));
    $inventory = $layout->observe();
    expect($inventory->current)->toBe(substr(ReleaseA, 0, 12))
        ->and($inventory->commits)->toBe([substr(ReleaseA, 0, 12) => ReleaseA, substr(ReleaseB, 0, 12) => ReleaseB])
        ->and($inventory->hostRetired)->toBeTrue();
    file_put_contents($layout->containerInventoryPath(), json_encode([...$state, 'old' => ReleaseA]));
    expect(fn () => $layout->observe())->toThrow(GatewayReleaseException::class);
});

it('observes a container Gateway that has no old release yet after the host was retired', function (): void {
    $layout = GatewayReleaseLayout::fromConfig();
    mkdir(dirname($layout->containerInventoryPath()), 0700, true);
    $state = ['version' => 2, 'live' => ReleaseA, 'old' => null, 'host_retired' => true];
    file_put_contents($layout->containerInventoryPath(), json_encode($state));
    $inventory = $layout->observe();
    expect($inventory->current)->toBe(substr(ReleaseA, 0, 12))
        ->and($inventory->retained)->toBe([substr(ReleaseA, 0, 12)])
        ->and($inventory->hostRetired)->toBeTrue();
});

it('schedules a corrective green descendant from the actual live SHA after a container migration failed', function (): void {
    $layout = GatewayReleaseLayout::fromConfig();
    config(['orbit.home' => dirname($layout->containerInventoryPath())]);
    mkdir(dirname($layout->containerInventoryPath()), 0700, true);
    file_put_contents($layout->containerInventoryPath(), json_encode(['version' => 2, 'live' => ReleaseA, 'old' => null]));
    file_put_contents($layout->currentPath().'/GATEWAY_ORIGIN', 'https://github.com/hardimpactdev/orbit');
    GatewayRelease::query()->create(['sha' => ReleaseB, 'requested' => ReleaseB, 'trigger' => 'auto', 'outcome' => 'failed',
        'force' => false, 'retryable' => false, 'migrations_ran' => true, 'phases' => [], 'duration_ms' => 1]);
    $green = Mockery::mock(GreenCommitResolver::class);
    $green->shouldReceive('resolve')->once()
        ->withArgs(fn (GitHubRepository $repo, string $branch, string $check, string $live, array $excluded): bool => $live === ReleaseA && $excluded === [ReleaseB])
        ->andReturn(new GreenCommit(ReleaseC, new GitHubCheckRun('Required checks', 'success', null)));
    app()->instance(GreenCommitResolver::class, $green);
    releaseAgent(static fn (string $sha): never => throw new GatewayReleaseException('prepare', 'gateway.release_agent_delivery_unavailable', 'Waiting for publication.', sha: $sha));
    $tick = app(RunAutomaticGatewayReleaseAction::class)->execute();
    expect($tick)->toMatchArray(['result' => 'waiting_for_agent_delivery', 'sha' => ReleaseC])
        ->and(app(GatewayReleaseAutomation::class)->pause())->toBeNull();
});
