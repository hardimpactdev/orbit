<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Data\Instances\InstanceAutoDeployData;
use App\Data\Instances\InstanceDeploymentData;
use App\Data\Operations\OperationData;
use App\Domain\Instances\Deployment\AutomaticDeployAlerts;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

// Run this unchanged on orbit-main to regenerate the golden; see deployments.md.
it('pins production receipts and real admission-created history to orbit-main', function (): void {
    Queue::fake();
    $this->travelTo('2026-11-01 12:00:00');
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'production', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2',
        'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'site', 'slug' => 'site', 'repository_url' => 'https://github.com/nckrtl/site.git',
        'default_branch' => 'main', 'root' => '.']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'environment' => 'production', 'status' => 'active', 'branch' => 'main', 'root' => '.',
        'source_is_laravel' => false, 'provisioning_step' => 'active',
        'production_user' => 'orbit-app-'.$project->id, 'production_home' => '/home/orbit-app-'.$project->id,
        'checkout_path' => '/home/orbit-app-'.$project->id.'/releases/initial']);
    InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'env_key' => 'APP_ENV', 'env_value' => 'production']);
    $notifier = new class implements ReleaseAlertNotifier
    {
        public ?string $summary = null;

        public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
        {
            $this->summary = $alert->summary;

            return new ReleaseAlertReceipt('test', null, ReleaseAlertStep::done(), ReleaseAlertStep::done());
        }
    };
    $actual = [];
    foreach ([false, true] as $enabled) {
        foreach ([null, 'Required checks'] as $gate) {
            $project->update(['merge_check' => $gate]);
            $instance->update(['auto_deploy' => $enabled]);
            $actual['public-policy/'.($enabled ? 'enabled' : 'disabled').'/'.($gate === null ? 'ungated' : 'gated')] = InstanceAutoDeployData::fromModel($instance->fresh())->toArray();
        }
    }
    $project->update(['merge_check' => null]);
    foreach ([true, false] as $pinned) {
        foreach (['success', 'failure', 'legacy-null-failure', 'start-refusal', 'queued-cancel', 'cancellation-request',
            'projection-unfinished', 'running-cancel', 'interruption', 'timeout', 'paused'] as $outcome) {
            $instance->deployments()->delete();
            Operation::query()->delete();
            $operation = app(StartInstanceDeployAction::class)->execute($instance, 'swamp:auto-deploy', null,
                $pinned ? str_repeat('b', 40) : null, $node->id);
            expect($instance->deployments()->count())->toBe(1);
            $row = $instance->deployments()->firstOrFail();
            expect($row->status)->toBe('running')->and($row->commit)->toBeNull();
            if ($outcome === 'queued-cancel') {
                app(CancelOperationAction::class)->execute($operation);
            } elseif (in_array($outcome, ['start-refusal', 'paused'], true)) {
                app(StartSwampRun::class)->refuse($operation, new ResourceOperationException(
                    $outcome === 'paused' ? 'deployment.paused' : 'swamp.start_refused', 'Refused', 503));
            } else {
                $operation->update(['status' => OperationStatus::Running, 'attempts' => 1,
                    'started_at' => now()->subDay(), 'state' => [...$operation->state, 'swamp_run_id' => 'guard-run']]);
                if ($outcome === 'timeout') {
                    app(ExpireOperationAction::class)->execute($operation);
                } elseif ($outcome === 'cancellation-request') {
                    // A request alone leaves the native run and admission history running.
                    $operation->update(['cancel_requested_at' => now()]);
                } elseif ($outcome !== 'projection-unfinished') {
                    $status = match ($outcome) {
                        'success' => 'succeeded', 'running-cancel' => 'cancelled', 'interruption' => 'interrupted', default => 'failed',
                    };
                    $steps = $outcome === 'legacy-null-failure' ? [] : [
                        'Read the release commit' => ['status' => 'succeeded', 'stdout' => str_repeat('b', 40)."\n"],
                    ];
                    app(DirectOperation::class)->projection($operation)->apply($operation, ['status' => $status,
                        'run_id' => 'guard-run', 'started_at' => now()->subDay()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
                        'result' => ['steps' => $steps, 'summary' => ['failed_steps' => $status === 'failed' ? ['Prepare shared paths'] : []]]]);
                }
            }
            $row = $row->fresh();
            $receipt = OperationData::summary($operation->fresh())->toArray();
            $history = InstanceDeploymentData::fromModel($row->fresh())->toArray();
            $receipt['id'] = 'operation';
            $history['id'] = 'history';
            // Generated IDs/releases vary with suite ordering; retain their presence and relationships.
            $normalize = static function (mixed $value) use (&$normalize, $operation, $row, $instance, $node): mixed {
                if (! is_array($value)) {
                    return $value;
                }
                foreach ($value as $key => $item) {
                    $value[$key] = match ($key) {
                        'id' => $item === $operation->id ? 'operation' : ($item === $row->id ? 'history' : $item),
                        'deployment_id' => $item === $row->id ? 'history' : $item,
                        'instance_id', 'target_id' => $item === $instance->id ? 'instance' : $item,
                        'caller_node_id', 'node_id' => $item === $node->id ? 'node' : $item,
                        'release', 'selected_release' => $item === null ? null : 'release',
                        default => $normalize($item),
                    };
                }

                return $value;
            };
            $notifier->summary = null;
            if (in_array($row->status, ['failed', 'interrupted'], true) && $row->commit !== null) {
                (new AutomaticDeployAlerts($notifier))->failed($instance, $row);
            }
            $advice = $notifier->summary === null ? null : str_replace(
                ['deployment '.$row->id.' ', 'Instance '.$instance->id.' '], ['deployment <history> ', 'Instance <instance> '], $notifier->summary);
            $actual[($pinned ? 'pinned' : 'unpinned').'/'.$outcome] = $normalize([
                'receipt' => $receipt, 'history' => $history, 'target_held' => $operation->fresh()->lock_key !== null,
                'checkout' => $instance->fresh()->checkout_path, 'alert' => $advice,
            ]);
        }
    }
    $golden = __DIR__.'/../../../Fixtures/production-deploy-orbit-main.json';
    if (getenv('ORBIT_PRODUCTION_GOLDEN_OUTPUT') !== false) {
        file_put_contents(getenv('ORBIT_PRODUCTION_GOLDEN_OUTPUT'), json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return;
    }
    expect($actual)->toBe(json_decode(file_get_contents($golden), true, flags: JSON_THROW_ON_ERROR));
});
