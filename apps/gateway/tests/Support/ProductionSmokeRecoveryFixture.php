<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Instances\DeployInstanceAction;
use App\Data\Instances\InstanceSmokeResultData;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Instances\SmokeArtifactEvidence;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use ZipArchive;

final class ProductionSmokeRecoveryFixture
{
    /** @return array{Orb220DeploymentApiFixture, array<string, mixed>, array<string, mixed>} */
    public static function create(): array
    {
        $fixture = Orb220DeploymentApiFixture::create();
        $instance = $fixture->instance;
        $instance->project->update(['repository_url' => 'https://github.com/acme/widgets.git']);
        $instance->update(['auto_deploy' => true]);
        $fixture->deployment->commits['fresh'] = str_repeat('b', 40);
        $route = ProductionSmokeRoute::create($instance);
        $selections = [];
        foreach (['fresh' => true, 'initial' => false] as $release => $passed) {
            $commit = str_repeat($passed ? 'b' : 'a', 40);
            $row = InstanceDeployment::query()->create(['instance_id' => $instance->id, 'release' => $release,
                'commit' => $commit, 'status' => 'succeeded', 'started_at' => now(), 'triggered_by' => 'swamp:auto-deploy']);
            $key = $passed ? 'proof.passed' : 'proof.failed';
            $input = ['deployment_id' => $row->id, 'commit' => $commit, 'release' => $release,
                'base_url' => 'https://smoke.example.test', 'workflow' => '.github/workflows/production-smoke.yml', 'ref' => 'main'];
            $result = InstanceSmokeResultData::from([...$input, 'run_id' => $row->id, 'run_attempt' => 1,
                'workflow_sha' => str_repeat('c', 40), 'run_url' => 'https://github.com/acme/widgets/actions/runs/'.$row->id,
                'conclusion' => $passed ? 'success' : 'failure', 'artifacts' => [['name' => 'production-smoke-'.$row->id.'-1',
                    'url' => 'https://github.com/acme/widgets/actions/runs/'.$row->id.'/artifacts/72']]]);
            $file = tmpfile();
            $name = stream_get_meta_data($file)['uri'];
            $zip = new ZipArchive;
            $zip->open($name, ZipArchive::OVERWRITE);
            $zip->addFromString('storage/framework/testing/smoke-target.json', json_encode([
                'commit' => $commit, 'deployment_id' => (string) $row->id, 'base_url' => $input['base_url'],
                'run_id' => (string) $row->id, 'run_attempt' => '1',
            ], JSON_THROW_ON_ERROR));
            $zip->addFromString('storage/framework/testing/smoke.xml', '<testsuite><testcase name="home">'
                .($passed ? '' : '<failure>Assertion failed</failure>').'</testcase></testsuite>');
            $zip->close();
            try {
                $verified = app(SmokeArtifactEvidence::class)->verify($name, $result);
            } finally {
                fclose($file);
            }
            Operation::query()->create(['kind' => 'instance.smoke', 'target_type' => 'instance_smoke',
                'target_id' => $instance->id, 'node_id' => $instance->node_id, 'idempotency_key' => $key,
                'input' => [...$input, 'caller_node_id' => $fixture->caller->id, 'repository_url' => $instance->project->repository_url,
                    'route_id' => $route->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1,
                'state' => ['intent' => now()->toIso8601String(), 'workflow' => ['id' => 42, 'sha' => str_repeat('c', 40)],
                    'smoke' => $verified->toArray()]]);
            $selections[$release] = ['deployment_id' => $row->id, 'commit' => $commit, 'release' => $release];
        }
        $failure = [...$selections['initial'], 'smoke_request_key' => 'proof.failed'];
        $rollback = ['failure_key' => 'smoke-failure.'.$instance->id.'.'.$failure['deployment_id'], 'expected' => $selections['initial'],
            'target' => $selections['fresh'], 'target_smoke_request_key' => 'proof.passed'];

        return [$fixture, $failure, $rollback];
    }

    public static function activated(Operation $op, Orb220DeploymentApiFixture $fixture): void
    {
        $initial = $fixture->deployment->selected($fixture->instance);
        $target = $fixture->deployment->retained($fixture->instance, 'fresh');
        $row = InstanceDeployment::query()->create(['instance_id' => $fixture->instance->id, 'kind' => 'rollback',
            'status' => 'running', 'started_at' => now(), 'triggered_by' => 'swamp:smoke']);
        app(OperationStore::class)->plan($op, array_map(
            fn (string $name) => new OperationStepPlan($name, true), ['rollback', 'activation', 'php_refresh', 'process_restart']));
        $op->refresh()->update(['state' => ['deployment_id' => $row->id, 'steps' => [
            'rollback' => ['previous' => DeployInstanceAction::storableRelease($initial),
                'release' => DeployInstanceAction::storableRelease($target)],
            'activation' => DeployInstanceAction::storableRelease($target),
        ]], 'status' => OperationStatus::Running, 'worker' => 'lost-worker', 'current_step' => 'php_refresh']);
        $op->steps()->whereIn('name', ['rollback', 'activation'])->update(['status' => 'succeeded']);
        $op->steps()->where('name', 'php_refresh')->update(['status' => 'running']);
        $fixture->deployment->current = 'fresh';
        $fixture->instance->update(['checkout_path' => $target->path]);
    }
}
