<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\UpdateInstanceAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Data\Instances\InstanceAutoDeployData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\DevelopmentDeployPolicy;
use App\Domain\Instances\Deployment\DevelopmentDeployRetries;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** Tests use the ordinary admission boundary; no Node, Swamp socket or source service is contacted. */
final class GatewayDevelopmentFixture
{
    public Instance $instance;

    public string $head;

    public array $checks = [];

    public int $sourceReads = 0;

    public ?Closure $duringSource = null;

    public function __construct()
    {
        Queue::fake();
        $this->head = str_repeat('b', 40);
        $node = Node::withoutEvents(fn () => Node::query()->create(['name' => 'development', 'status' => 'active', 'platform' => 'linux',
            'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit', 'instance_bridge' => '10.253.7.0/24',
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        $control = Node::withoutEvents(fn () => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209']));
        $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
        $node->forceFill(['instance_bridge' => '10.253.7.0/24'])->save();
        $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
        $project = Project::query()->create(['name' => 'development', 'slug' => 'development', 'type' => 'node-package',
            'repository_url' => 'https://github.com/example/development.git', 'default_branch' => 'main', 'root' => '.']);
        $this->instance = Instance::withoutEvents(fn () => Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
            'name' => 'default', 'status' => 'active', 'source_layout' => 'checkout', 'checkout_path' => '/srv/development/default', 'auto_deploy' => true]));
        InstanceRuntime::query()->create(['instance_id' => $this->instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$this->instance->id, 'bridge_address' => '10.253.7.2', 'runtime_version' => 'r', 'agent_version' => 'a',
            'power_state' => 'stopped', 'seed_commit' => str_repeat('a', 40)]);
        $fixture = $this;
        app()->instance(ProjectSourceReader::class, new class($fixture) implements ProjectSourceReader
        {
            public function __construct(private GatewayDevelopmentFixture $fixture) {}

            public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths, ?string $commit = null): array
            {
                $this->fixture->sourceReads++;
                ($this->fixture->duringSource ?? static function (): void {})();

                return ['head' => $commit ?? $this->fixture->head, 'files' => array_fill_keys($paths, null)];
            }
        });
        app(GitHubAppStore::class)->put(new GitHubAppCredentials(1, 'fixture', 'fixture', 'example', 'Organization', 'https://github.com/apps/fixture', 'test-key'));
        $github = Mockery::mock(GitHubApi::class);
        $github->shouldReceive('repositoryInstallation')->andReturn(1);
        $github->shouldReceive('repositoryChecksToken')->andReturn('test-token');
        $github->shouldReceive('checkRuns')->andReturnUsing(fn () => $this->checks);
        app()->instance(GitHubApi::class, $github);
    }

    public function gate(string $conclusion = 'success', string $status = 'completed', ?string $sha = null, string $name = 'Required checks', string $app = 'github-actions'): void
    {
        $this->instance->project->update(['merge_check' => 'Required checks']);
        $this->checks = [new GitHubCheckRun($name, $conclusion, null, status: $status, headSha: $sha ?? $this->head, appSlug: $app)];
    }

    public function tick(?string $at = null): string
    {
        if ($at !== null) {
            Carbon::setTestNow($at);
        }

        return app(DevelopmentDeployPolicy::class)->check($this->instance, $this->head);
    }

    public function operation(string $status = 'failed', ?string $code = 'operation.native_failed', ?string $commit = null, bool $automatic = true): Operation
    {
        return Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'node_id' => $this->instance->node_id, 'status' => $status, 'attempts' => 0, 'sequence' => 1, 'input' => [
                'commit' => $commit ?? $this->head, 'deployment' => ['variant' => 'container'], 'development_policy' => $automatic,
            ], 'triggered_by' => $automatic ? AutomaticDeployHistory::Trigger : 'operator', 'error_code' => $code,
            'finished_at' => in_array($status, OperationStatus::terminalValues(), true) ? now() : null]);
    }

    public function entry(?string $commit = null): ?array
    {
        return app(DevelopmentDeployRetries::class)->read($this->instance->fresh(), [$commit ?? $this->head])['commits'][0] ?? null;
    }

    public function fail(string $status = 'failed', ?string $code = 'operation.native_failed', string $boundary = 'refused'): Operation
    {
        $operation = $this->operation($status, $code);
        app(InstanceDeployProjection::class)->{$boundary}($operation);
        app(InstanceDeployProjection::class)->{$boundary}($operation->fresh());
        app(DevelopmentDeployRetries::class)->observe($this->instance);

        return $operation;
    }

    public function reset(): void
    {
        $operation = $this->operation('succeeded', null, automatic: false);
        $instance = $this->instance->fresh();
        app(DevelopmentDeployRetries::class)->manual($instance, $operation->id);
        $instance->save();
    }
}

it('maps every reference Deno registration to an executable Pest scenario', function (): void {
    $table = json_decode(file_get_contents(base_path('tests/Fixtures/development-deploy-matrix.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($table['source_commit'])->toBe('e635db82b8a02688acd7f018193e0660883597a2')
        ->and($table['cells'])->toHaveCount(208)
        ->and(array_column($table['cells'], 'deno'))->toBe($table['registrations'])
        ->and(count(array_unique($table['registrations'])))->toBe(208);
    foreach ($table['cells'] as $cell) {
        expect($cell['pest'])->toBe('ports development cell '.$cell['deno'])
            ->and($cell['scenario'])->not->toBeEmpty();
    }
});

$developmentCells = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/development-deploy-matrix.json'), true, flags: JSON_THROW_ON_ERROR)['cells'];
foreach ($developmentCells as $cell) {
    it('ports development cell '.$cell['deno'], function () use ($cell): void {
        $this->travelTo(new DateTimeImmutable('2026-10-31T05:00:00Z'));
        $f = new GatewayDevelopmentFixture;
        // Checkpoint intent transport is retired. Every old intent variant must reach the same database-owned decision.
        $f->instance->update(['auto_deploy_state' => ['retired_checkpoint' => $cell['intent'] ?? 'none']]);
        $scenario = $cell['scenario'];
        if ($scenario === 'terminal') {
            $outcome = $cell['outcome'];
            $status = match ($outcome) {
                'success' => 'succeeded', 'interruption' => 'interrupted', 'queued-cancel', 'running-cancel', 'absent-admission', 'refused-before-admission' => 'cancelled', default => 'failed'
            };
            $code = match ($outcome) {
                'paused' => 'deployment.paused', 'timeout' => 'operation.timed_out', 'start-refusal' => 'swamp.start_refused', 'absent-admission', 'refused-before-admission' => 'operation.admission_fenced', default => 'operation.native_failed'
            };
            $op = $f->fail($status, $code, $outcome === 'timeout' ? 'timedOut' : 'refused');
            $ignored = in_array($outcome, ['success', 'paused', 'absent-admission', 'refused-before-admission'], true);
            expect($f->entry()['attempts'] ?? 0)->toBe($ignored ? 0 : 1);
            if (! $ignored) {
                expect($f->tick())->toBe('backing_off');
                expect($f->tick('2026-10-31T05:09:59Z'))->toBe('backing_off');
                expect($f->tick('2026-10-31T05:10:00Z'))->toBe('started');
                $active = Operation::query()->latest('id')->firstOrFail();
                $active->update(['status' => 'failed', 'finished_at' => now(), 'lock_key' => null]);
                app(InstanceDeployProjection::class)->refused($active);
                expect($f->tick('2026-10-31T05:29:59Z'))->toBe('backing_off');
                expect($f->tick('2026-10-31T05:30:00Z'))->toBe('started');
                $active = Operation::query()->latest('id')->firstOrFail();
                $active->update(['status' => 'failed', 'finished_at' => now(), 'lock_key' => null]);
                app(InstanceDeployProjection::class)->refused($active);
                expect($f->tick('2026-11-01T05:00:00Z'))->toBe('commit_failed');
            }
            $f->head = str_repeat('c', 40);
            $f->gate();
            expect($f->tick())->toBe('started');
        } elseif ($scenario === 'gate') {
            $variant = $cell['variant'];
            $f->gate($variant === 'red' ? 'failure' : 'success', $variant === 'running' ? 'in_progress' : 'completed',
                $variant === 'wrong-sha' ? str_repeat('c', 40) : null, $variant === 'unrelated' ? 'Other check' : 'Required checks',
                $variant === 'wrong-app' ? 'external-app' : 'github-actions');
            if ($variant === 'missing') {
                $f->checks = [];
            }
            expect($f->tick())->toBe(match ($variant) {
                'green' => 'started', 'red', 'wrong-sha', 'wrong-app' => 'gate_failed', default => 'gate_pending'
            });
            if ($variant !== 'green') {
                expect(Operation::query()->count())->toBe(0);
                $f->gate();
                expect($f->tick())->toBe('started');
            }
        } elseif ($scenario === 'busy') {
            $f->operation($cell['status'] ?? 'running', null, automatic: ($cell['manual'] ?? false) !== true);
            expect($f->tick())->toBe('busy')->and($f->sourceReads)->toBe(0);
        } elseif ($scenario === 'selection') {
            match ($cell['variant']) {
                'checkout' => $f->instance->runtime()->delete(),
                'unseeded' => $f->instance->runtime()->update(['seed_commit' => null]),
                'clone' => (function () use ($f): void {
                    $f->instance->runtime()->delete();
                    $f->instance->update(['name' => 'feature']);
                })(),
                'off' => $f->instance->update(['auto_deploy' => false]),
                'inactive' => $f->instance->node->update(['status' => 'inactive']),
                'production' => $f->instance->node->roles()->update(['role' => 'app-prod']),
                'ambiguous' => $f->instance->node->roles()->create(['role' => 'app-prod', 'status' => 'active']),
            };
            expect($f->tick())->toBe('paused')->and($f->sourceReads)->toBe(0)->and(Operation::query()->count())->toBe(0);
        } elseif ($scenario === 'toggle') {
            for ($i = 0; $i < $cell['failures']; $i++) {
                $f->fail();
            }
            $before = $f->entry();
            app(UpdateInstanceAction::class)->execute($f->instance, null, autoDeploy: false);
            expect($f->tick())->toBe('paused');
            app(UpdateInstanceAction::class)->execute($f->instance, null, autoDeploy: true);
            expect($f->entry())->toBe($before);
            expect($f->tick())->toBe($cell['failures'] === 3 ? 'commit_failed' : 'backing_off');
        } elseif ($scenario === 'manual-reset') {
            $f->fail();
            $f->reset();
            expect($f->entry())->toBeNull()->and($f->tick())->toBe('started');
        } elseif ($scenario === 'durable') {
            $operations = [$f->fail(), $f->fail(), $f->fail()];
            if (($cell['reset'] ?? false) === true) {
                $f->reset();
            }
            for ($i = 0; $i < ($cell['rows'] ?? 51); $i++) {
                InstanceDeployment::query()->create(['instance_id' => $f->instance->id, 'kind' => 'deploy', 'status' => 'succeeded',
                    'commit' => hash('sha1', (string) $i), 'started_at' => now(), 'finished_at' => now(), 'triggered_by' => AutomaticDeployHistory::Trigger]);
            }
            $last = InstanceDeployment::query()->latest('id')->firstOrFail();
            app(InstanceDeploymentRecorder::class)->recordNative($last, 'succeeded', null, ['release' => null, 'selected_release' => null, 'commit' => $last->commit, 'failed_step' => null]);
            expect(InstanceDeployment::query()->where('instance_id', $f->instance->id)->count())->toBe(50);
            foreach ($operations as $op) {
                app(InstanceDeployProjection::class)->refused($op->fresh());
            }
            expect($f->entry()['attempts'] ?? 0)->toBe(($cell['reset'] ?? false) ? 0 : 3);
            expect($f->tick('2026-12-01T05:00:00Z'))->toBe(($cell['reset'] ?? false) ? 'started' : 'commit_failed');
        } elseif ($scenario === 'success') {
            $op = $f->fail();
            $success = $f->operation('succeeded', null);
            app(DevelopmentDeployRetries::class)->settled($success);
            app(DevelopmentDeployRetries::class)->settled($op);
            expect($f->entry()['attempts'])->toBe(0);
            $f->instance->runtime()->update(['seed_commit' => $f->head]);
            expect($f->tick())->toBe('up_to_date');
        } elseif ($scenario === 'interleaved') {
            $old = $f->head;
            $f->fail();
            $f->head = str_repeat('c', 40);
            $f->gate();
            expect($f->tick())->toBe('started')->and($f->entry($old)['attempts'])->toBe(1)->and($f->entry())->toBeNull();
        } elseif ($scenario === 'source-race') {
            $f->duringSource = function () use ($f): void {
                $f->instance->update(['auto_deploy' => false]);
            };
            expect($f->tick())->toBe('busy')->and(Operation::query()->count())->toBe(0);
        } elseif ($scenario === 'execution-refusal') {
            expect($f->tick())->toBe('started');
            $op = Operation::query()->latest('id')->firstOrFail();
            $f->instance->update(['auto_deploy' => false]);
            expect(fn () => DevelopmentDeployPolicy::assertExecutable($op))->toThrow(ResourceOperationException::class);
            $op->update(['status' => 'failed', 'error_code' => 'deployment.paused', 'finished_at' => now()]);
            app(InstanceDeployProjection::class)->refused($op);
            expect($f->entry())->toBeNull();
        } elseif ($scenario === 'retained-old') {
            $old = $f->head;
            $op = $f->operation($cell['status']);
            if ($cell['history']) {
                InstanceDeployment::query()->create(['instance_id' => $f->instance->id, 'kind' => 'deploy', 'status' => $cell['status'],
                    'commit' => $old, 'started_at' => now(), 'finished_at' => now(), 'triggered_by' => AutomaticDeployHistory::Trigger]);
            }
            $f->head = str_repeat('c', 40);
            $f->gate();
            expect($f->tick())->toBe('started')->and($f->entry($old)['attempts'])->toBe($cell['status'] === 'succeeded' ? 0 : 1)
                ->and($f->entry())->toBeNull();
        } elseif ($scenario === 'old-success') {
            $f->operation('succeeded', null);
            $f->head = str_repeat('c', 40);
            $f->gate($cell['green'] ? 'success' : 'failure');
            expect($f->tick())->toBe($cell['green'] ? 'started' : 'gate_failed')
                ->and($f->instance->runtime()->sole()->seed_commit)->toBe(str_repeat('a', 40));
        } elseif ($scenario === 'interleaved-wait') {
            $old = $f->head;
            $f->fail();
            $f->head = str_repeat('c', 40);
            $f->gate('success', 'in_progress');
            expect($f->tick())->toBe('gate_pending')->and($f->entry($old)['attempts'])->toBe(1)->and($f->entry())->toBeNull();
            $f->gate();
            expect($f->tick())->toBe('started')->and($f->entry($old)['attempts'])->toBe(1);
        } elseif ($scenario === 'ownership') {
            expect(InstanceAutoDeployData::fromModel($f->instance)->policyOwner)->toBe('gateway');
            expect(fn () => AutomaticDeployPolicyWriter::Swamp->assertSwamp($f->instance))
                ->toThrow(ResourceOperationException::class);
            expect($f->tick())->toBe('started');
        } elseif ($scenario === 'ended-receipts') {
            foreach (['swamp.start_uncertain', 'swamp.start_refused', 'swamp.start_unavailable', 'swamp.vault_sync_unavailable', 'operation.native_failed'] as $code) {
                foreach (['failed', 'cancelled', 'interrupted'] as $status) {
                    $f->head = hash('sha1', $code.$status);
                    $f->fail($status, $code);
                    expect($f->entry()['attempts'])->toBe(1)->and($f->tick())->toBe('backing_off');
                }
            }
        } elseif ($scenario === 'route') {
            $route = Route::query()->create(['project_id' => $f->instance->project_id, 'node_id' => $f->instance->node_id,
                'domain' => 'development.example.test', 'status' => 'pending', 'provenance' => 'explicit', 'publication' => 'private']);
            $route->targets()->create(['instance_id' => $f->instance->id, 'position' => 0]);
            $route->update(['status' => 'active']);
            $f->instance->update(['development_projection_pending' => true]);
            expect($f->tick())->toBe('busy')->and(Operation::query()->count())->toBe(0)->and($f->sourceReads)->toBe(0);
            $f->instance->update(['development_projection_pending' => false]);
            expect($f->tick())->toBe('started');
        } elseif ($scenario === 'late-history') {
            $op = $f->fail();
            $row = InstanceDeployment::query()->create(['instance_id' => $f->instance->id, 'kind' => 'deploy', 'status' => 'failed',
                'commit' => null, 'started_at' => now(), 'finished_at' => now(), 'triggered_by' => AutomaticDeployHistory::Trigger]);
            $op->update(['result' => ['deployment_id' => $row->id]]);
            app(DevelopmentDeployRetries::class)->settled($op);
            $row->update(['commit' => $f->head]);
            app(DevelopmentDeployRetries::class)->settled($op->fresh());
            expect($f->entry()['attempts'])->toBe(1)->and($f->tick())->toBe('backing_off');
        } elseif ($scenario === 'original-command') {
            $old = $f->head;
            $op = $f->operation($cell['status']);
            $f->head = str_repeat('c', 40);
            expect($f->tick())->toBe('started')->and($f->tick())->toBe('busy');
            expect($f->entry($old)['attempts'])->toBe($cell['status'] === 'succeeded' ? 0 : 1);
            expect($f->entry())->toBeNull();
        } elseif ($scenario === 'after-execution') {
            $op = $f->operation('failed');
            $f->instance->update(['auto_deploy' => false]);
            $f->instance->runtime()->delete();
            app(InstanceDeployProjection::class)->refused($op);
            app(InstanceDeployProjection::class)->refused($op);
            expect($f->entry()['attempts'])->toBe(1)->and($f->tick())->toBe('paused');
        } elseif ($scenario === 'toggle-active') {
            expect($f->tick())->toBe('started');
            $op = Operation::query()->sole();
            $op->update(['status' => $cell['status']]);
            app(UpdateInstanceAction::class)->execute($f->instance, null, autoDeploy: false);
            expect($f->tick())->toBe('paused');
            app(UpdateInstanceAction::class)->execute($f->instance, null, autoDeploy: true);
            expect($f->tick())->toBe('busy')->and(Operation::query()->count())->toBe(1)->and($f->entry())->toBeNull();
        } elseif ($scenario === 'interleaved-active') {
            $op = $f->operation($cell['status'], null);
            $f->head = str_repeat('c', 40);
            if ($cell['status'] !== 'succeeded') {
                expect($f->tick())->toBe('busy');
                $op->update(['status' => 'succeeded', 'finished_at' => now()]);
            }
            $f->instance->runtime()->update(['seed_commit' => str_repeat('b', 40)]);
            expect($f->tick())->toBe('started')->and(Operation::query()->latest('id')->firstOrFail()->input['commit'])->toBe($f->head);
        } elseif ($scenario === 'gate-changed') {
            $f->gate('failure');
            expect($f->tick())->toBe('gate_failed');
            $f->gate();
            $f->instance->project->update(['merge_check' => 'New check']);
            expect($f->tick())->toBe('gate_pending')->and(Operation::query()->count())->toBe(0);
            $f->checks = [new GitHubCheckRun('New check', 'success', null, status: 'completed', headSha: $f->head, appSlug: 'github-actions')];
            expect($f->tick())->toBe('started');
        } elseif ($scenario === 'write-rollback') {
            $op = $f->operation();
            try {
                DB::transaction(function () use ($op): void {
                    app(DevelopmentDeployRetries::class)->settled($op);
                    throw new RuntimeException('failed write');
                });
            } catch (RuntimeException $error) {
                expect($error->getMessage())->toBe('failed write');
            }
            expect($f->entry())->toBeNull();
            app(DevelopmentDeployRetries::class)->settled($op);
            app(DevelopmentDeployRetries::class)->settled($op);
            expect($f->entry()['attempts'])->toBe(1)->and($f->tick())->toBe('backing_off');
        } elseif ($scenario === 'bounded') {
            $f->fail();
            $entry = $f->entry();
            for ($offset = 0; $offset < 1000; $offset += 50) {
                $rows = [];
                for ($i = $offset; $i < $offset + 50; $i++) {
                    $rows[] = ['instance_id' => $f->instance->id, 'commit' => hash('sha1', (string) $i), 'state' => json_encode([...$entry, 'attempts' => 3, 'stopped' => true])];
                }
                DB::table('development_deploy_retries')->insert($rows);
            }
            expect(app(DevelopmentDeployRetries::class)->read($f->instance->fresh(), [$f->head])['commits'])->toHaveCount(1);
            expect($f->tick())->toBe('backing_off')->and($f->sourceReads)->toBe(0);
        } elseif ($scenario === 'admission') {
            expect($f->tick())->toBe('started');
            $op = Operation::query()->sole();
            expect($op->kind)->toBe('instance.deploy')->and($op->input['commit'])->toBe($f->head)
                ->and($op->input['development_policy'])->toBeTrue()->and($op->triggered_by)->toBe(AutomaticDeployHistory::Trigger);
            expect($f->tick())->toBe('busy')->and(Operation::query()->count())->toBe(1);
        } else {
            throw new LogicException('Unmapped Deno cell: '.$cell['deno']);
        }
    });
}

it('accounts for the admitted pin through real apply and replay even when failed native output has no SHA', function (): void {
    $f = new GatewayDevelopmentFixture;
    expect($f->tick())->toBe('started');
    $operation = Operation::query()->sole();
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => 'pin-run']]);
    $data = ['status' => 'failed', 'run_id' => 'pin-run', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'operation.native_failed', 'result' => [
        'steps' => [], 'summary' => ['failed_steps' => ['Check out in the container']]]];
    $projector = app(InstanceDeployProjection::class);
    $projector->apply($operation, $data);
    $projector->apply($operation->fresh(), $data);
    expect($f->entry()['attempts'])->toBe(1)->and($operation->fresh()->result['commit'])->toBe($f->head)
        ->and(InstanceDeployment::query()->sole()->commit)->toBe($f->head);
});

it('resets retries at real manual development admission and cannot recount its earlier receipts', function (): void {
    $f = new GatewayDevelopmentFixture;
    $old = [$f->fail(), $f->fail(), $f->fail()];
    expect($f->entry()['stopped'])->toBeTrue();
    $manual = app(StartInstanceDeployAction::class)->execute($f->instance, 'operator', null, $f->head);
    expect($manual->status)->toBe(OperationStatus::Queued)->and($f->entry())->toBeNull();
    foreach ($old as $operation) {
        app(InstanceDeployProjection::class)->refused($operation);
    }
    expect($f->entry())->toBeNull()->and($f->tick())->toBe('busy');
});

it('preserves a timeout hold and counts once until the existing reconciliation path proves a late success', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-10-31T05:00:00Z'));
    $f = new GatewayDevelopmentFixture;
    expect($f->tick())->toBe('started');
    $op = Operation::query()->sole();
    $op->update(['status' => 'running', 'started_at' => now(), 'attempts' => 1, 'state' => ['swamp_run_id' => 'timeout-run']]);
    $this->travelTo(new DateTimeImmutable('2026-10-31T06:00:00Z'));
    $expiry = app(ExpireOperationAction::class);
    $expired = $expiry->execute($op->fresh());
    app(InstanceDeployProjection::class)->timedOut($expired);
    expect($expired->error_code)->toBe('operation.timed_out')->and($expired->lock_key)->not->toBeNull()->and($f->entry()['attempts'])->toBe(1);
    expect($f->tick('2026-11-01T05:00:00Z'))->toBe('busy')->and(Operation::query()->count())->toBe(1);
    $success = ['status' => 'succeeded', 'run_id' => 'timeout-run', 'started_at' => '2026-10-31T05:00:00Z', 'finished_at' => '2026-10-31T05:01:00Z', 'result' => ['steps' => [
        'Read the container result' => ['stdout' => json_encode(['commit' => $f->head])]], 'summary' => ['failed_steps' => []]]];
    expect(fn () => app(InstanceDeployProjection::class)->apply($expired, $success))->toThrow(ResourceOperationException::class);
    expect($f->entry()['attempts'])->toBe(1);
    // The shared reader calls reopen only after verifying that this exact native root met its budget.
    $reopened = $expiry->reopen($expired, 'timeout-run');
    expect($reopened->status)->toBe(OperationStatus::Running);
    app(InstanceDeployProjection::class)->apply($reopened, $success);
    expect($f->entry()['attempts'])->toBe(0)->and($f->tick())->toBe('up_to_date');
});

it('does not let an older reconciled success erase failures of later admissions', function (): void {
    $f = new GatewayDevelopmentFixture;
    $older = $f->operation('failed', 'operation.timed_out');
    app(DevelopmentDeployRetries::class)->settled($older);
    $later = $f->fail();
    $older->update(['status' => 'succeeded', 'error_code' => null]);
    app(DevelopmentDeployRetries::class)->settled($older);
    expect($f->entry()['attempts'])->toBe(1)->and($f->entry()['evidence'][0]['operation_id'])->toBe($later->id);
});
