<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\StepLists\InstanceRunStepMaterial;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Operation;
use App\Models\ProjectLifecycleStep;
use Illuminate\Support\Facades\Exceptions;

describe('private app-user step material', function (): void {
    beforeEach(function (): void {
        [$this->control, $this->node, $project, $this->instance] = deployment_api_fixture();
        $project->update(['type' => 'monorepo']);
        $user = 'orbit-app-'.$project->id;
        $this->instance->update(['production_user' => $user, 'production_home' => '/home/'.$user,
            'checkout_path' => '/home/'.$user.'/releases/initial', 'source_is_laravel' => false,
            'status' => 'active', 'provisioning_step' => 'active']);
        app(InstanceDeployStepStore::class)->replaceAll($this->instance, [new DeploymentStep('install', DeploymentPhase::BeforeActivation, 'private-program-sentinel', 30)]);
        $this->instance->environmentValues()->create(['env_key' => 'APP_SECRET', 'env_value' => 'private-value-sentinel']);
        $this->operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'node_id' => $this->node->id, 'status' => OperationStatus::Running, 'attempts' => 0,
            'state' => ['instance_writer' => 'direct', 'swamp_run_id' => 'f34f444b-601b-4444-b814-ce7361337489'],
            'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($this->instance)]]);
        $this->node->forceFill(['agent_secret_hash' => hash('sha256', 'private-agent-secret')])->save();
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])->withToken('private-agent-secret');
        $this->url = '/api/v1/agent/operations/'.$this->operation->id.'/step?'.http_build_query([
            'step' => 'before_activation.install', 'digest' => hash('sha256', 'private-program-sentinel')]);
    });

    it('returns bounded no-store material only to the admitted Node without persisting it', function (): void {
        $before = $this->operation->fresh()->getRawOriginal();
        $this->getJson($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.project', $this->instance->project_id)
            ->assertJsonPath('data.script', 'private-program-sentinel')
            ->assertJsonPath('data.environment.APP_SECRET', 'private-value-sentinel')
            ->assertJsonPath('data.release', InstanceDeploymentIntent::releaseName($this->operation))
            ->assertJsonMissingPath('data.user')->assertJsonMissingPath('data.home');
        expect(Activity::query()->count())->toBe(0)
            ->and($this->operation->fresh()->getRawOriginal())->toBe($before);
    });

    it('refreshes a stale intent before checking cancellation', function (): void {
        $stale = $this->operation->fresh();
        $this->operation->update(['cancel_requested_at' => now()]);
        expect(fn () => app(InstanceRunStepMaterial::class)->read(
            $stale, $this->node->id, 'before_activation.install', hash('sha256', 'private-program-sentinel'),
        ))->toThrow(ResourceOperationException::class);
    });

    it('requires the existing agent secret', function (): void {
        $this->withHeader('Authorization', '')->getJson($this->url)->assertUnauthorized()->assertJsonPath('error.code', 'agent.secret_required');
        $this->withToken('wrong')->getJson($this->url)->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
    });

    it('authenticates before binding a nonexistent Operation', function (): void {
        $url = '/api/v1/agent/operations/999999999/step?step=setup.install&digest='.str_repeat('a', 64);
        $this->withHeader('Authorization', '')->getJson($url)->assertUnauthorized()->assertJsonPath('error.code', 'agent.secret_required');
        $this->withToken('wrong')->getJson($url)->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
        $this->withToken('private-agent-secret')->getJson($url)->assertNotFound();
    });

    it('refuses material after the Instance moves to another Node', function (): void {
        $this->instance->update(['node_id' => $this->control->id]);
        $this->getJson($this->url)->assertConflict()->assertJsonPath('error.code', 'instance.step_not_admitted');
    });

    it('refuses the configured control plane as a foreign Node', function (): void {
        config(['dependency_detection.services_node_id' => $this->control->id]);
        $this->control->forceFill(['agent_secret_hash' => hash('sha256', 'control-secret')])->save();
        $this->withServerVariables(['REMOTE_ADDR' => $this->control->wireguard_ip])->withToken('control-secret')
            ->getJson($this->url)->assertForbidden()->assertJsonPath('error.code', 'operation.execution_forbidden');
        expect(Activity::query()->count())->toBe(0);
    });

    it('refuses noncurrent intent without returning private values', function (array $changes): void {
        $this->operation->forceFill($changes)->save();
        $response = $this->getJson($this->url)->assertConflict()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('error.code', 'instance.step_not_admitted');
        expect($response->getContent())->not->toContain('private-program-sentinel', 'private-value-sentinel', 'private-agent-secret')
            ->and(Activity::query()->count())->toBe(0);
    })->with([
        'terminal' => [['status' => OperationStatus::Succeeded]],
        'waiting' => [['status' => OperationStatus::Waiting]],
        'cancelled' => [['cancel_requested_at' => '2026-10-30 12:00:00']],
        'polled' => [['state' => ['instance_writer' => 'polled', 'swamp_run_id' => 'f34f444b-601b-4444-b814-ce7361337489']]],
        'unbound' => [['state' => ['instance_writer' => 'direct']]],
        'wrong kind' => [['kind' => 'instance.rollback']],
    ]);

    it('refuses a changed or missing command against its admitted digest', function (bool $remove): void {
        if ($remove) {
            $this->instance->deploySteps()->delete();
        } else {
            $this->instance->deploySteps()->update(['command' => 'changed-private-program']);
        }
        $this->getJson($this->url)->assertConflict()->assertJsonPath('error.code', 'instance.step_not_admitted');
    })->with([false, true]);

    it('refuses deadlines above the shared action ceiling', function (): void {
        $input = $this->operation->input;
        $input['deployment']['steps'][0]['timeout_seconds'] = 301;
        $this->operation->update(['input' => $input]);
        $this->getJson($this->url)->assertConflict()->assertJsonPath('error.code', 'instance.step_not_admitted');
    });

    it('refuses caller-selected command or environment fields', function (): void {
        $this->getJson($this->url.'&command=id&environment=private-value-sentinel')->assertUnprocessable();
    });

    it('refuses an encoded response above the private material bound', function (): void {
        for ($index = 0; $index < 16; $index++) {
            $this->instance->environmentValues()->create(['env_key' => 'BOUNDED_'.$index, 'env_value' => str_repeat("\u{0080}", 30_000)]);
        }
        $material = app(InstanceRunStepMaterial::class)->read($this->operation, $this->node->id,
            'before_activation.install', hash('sha256', 'private-program-sentinel'));
        expect(strlen(json_encode(['data' => $material], JSON_THROW_ON_ERROR)))->toBeGreaterThan(2 * 1024 * 1024);
        $response = $this->getJson($this->url)->assertConflict()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('error.code', 'instance.step_not_admitted');
        expect(strlen($response->getContent()))->toBeLessThan(256)
            ->and($response->getContent())->not->toContain('private-value-sentinel');
    });

    it('keeps protected material errors out of errors and reporting', function (): void {
        Exceptions::fake();
        $this->instance->environmentValues()->create(['env_key' => 'BROKEN', 'env_value' => '{{private-value-sentinel}}']);
        $response = $this->getJson($this->url)->assertConflict()->assertJsonPath('error.code', 'instance.step_not_admitted');
        expect($response->getContent())->not->toContain('private-value-sentinel', 'BROKEN');
        Exceptions::assertNothingReported();
    });

    it('returns setup material only for a matching privately resolved command and admitted release', function (): void {
        $steps = app(ProjectLifecycleStepStore::class);
        $steps->create($this->instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'setup-private-command', 30), null, null);
        $admission = app(StartInstanceStepListAction::class);
        $this->operation->update(['kind' => 'instance.run_steps', 'input' => ['instance_id' => $this->instance->id,
            'source' => 'project', 'list' => 'setup', 'configuration_digest' => $admission->configurationDigest($this->instance->project, LifecyclePhase::Setup),
            ...$admission->localAdmission($this->instance)]]);
        $url = '/api/v1/agent/operations/'.$this->operation->id.'/step?'.http_build_query(['step' => 'setup.install', 'digest' => hash('sha256', 'setup-private-command')]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.release', 'initial')->assertJsonPath('data.script', 'setup-private-command');
        ProjectLifecycleStep::query()->where('project_id', $this->instance->project_id)->where('name', 'install')->update(['command' => 'changed-private-command']);
        $this->getJson($url)->assertConflict()->assertJsonPath('error.code', 'instance.step_not_admitted');
        ProjectLifecycleStep::query()->where('project_id', $this->instance->project_id)->where('name', 'install')->update(['command' => 'setup-private-command']);
        foreach (['../foreign', 'bad/release', '.', '..'] as $badName) {
            $directory = $this->instance->production_home.'/releases/'.$badName;
            $this->instance->update(['checkout_path' => $directory]);
            $input = $this->operation->input;
            $input['release_directory'] = $directory;
            $this->operation->update(['input' => $input]);
            $this->getJson($url)->assertConflict()->assertJsonPath('error.code', 'instance.step_not_admitted');
        }
        $this->instance->update(['checkout_path' => $this->instance->production_home.'/releases/replaced']);
        $this->getJson($url)->assertConflict();
        $input = $this->operation->input;
        $input['release_directory'] = '/tmp/foreign';
        $this->operation->update(['input' => $input]);
        $this->getJson($url)->assertConflict();
    });
});
