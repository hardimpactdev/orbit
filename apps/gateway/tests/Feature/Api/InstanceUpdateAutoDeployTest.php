<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\InstanceDeployment;

beforeEach(function (): void {
    [$this->caller, $this->owner, $this->project, $this->instance] = deployment_api_fixture();
    $this->project->update(['repository_url' => 'https://github.com/acme/shop.git', 'merge_check' => 'Required checks']);
    $this->update = fn (array $body) => $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->patchJson("/api/v1/instances/{$this->instance->id}", $body);
});

describe('setting automatic deployment of an Instance', function (): void {
    it('turns automatic deployment on behind the merge check and resumes after the newest deployment', function (): void {
        $row = InstanceDeployment::query()->create(['instance_id' => $this->instance->id, 'branch' => 'main', 'started_at' => now(), 'status' => 'succeeded', 'triggered_by' => 'laptop', 'kind' => 'rollback']);

        ($this->update)(['auto_deploy' => true])
            ->assertOk()
            ->assertJsonPath('data.auto_deploy.enabled', true)
            ->assertJsonPath('data.auto_deploy.gate', 'Required checks')
            ->assertJsonPath('data.auto_deploy.warning', null);

        $instance = $this->instance->fresh();
        expect($instance->auto_deploy)->toBeTrue()
            ->and($instance->auto_deploy_resumed_after)->toBe($row->id)
            ->and($instance->deployment_branch)->toBeNull();
    });

    it('warns that every commit deploys when the Project has no merge check', function (string $sourceAccess): void {
        $this->project->update(['merge_check' => null, 'source_access' => $sourceAccess]);

        ($this->update)(['auto_deploy' => true])
            ->assertOk()
            ->assertJsonPath('data.auto_deploy.enabled', true)
            ->assertJsonPath('data.auto_deploy.gate', null)
            ->assertJsonPath('data.auto_deploy.warning', 'The Project has no merge_check, so every new commit on the branch deploys without a check.');
    })->with(['GitHub App' => 'github_app', 'GitHub CLI' => 'gh_cli']);

    it('refuses a merge check that the Gateway GitHub App cannot read and changes nothing', function (array $project): void {
        $this->project->update($project);

        ($this->update)(['auto_deploy' => true, 'branch' => 'release'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'instance.auto_deploy_gate_unreadable');

        expect($this->instance->fresh()->auto_deploy)->toBeFalse()
            ->and($this->instance->fresh()->deployment_branch)->toBeNull();
    })->with([
        'GitHub CLI' => [['source_access' => 'gh_cli']],
        'another host' => [['repository_url' => 'https://example.test/shop.git']],
    ]);

    it('sets the branch and automatic deployment together, and turns it off again', function (): void {
        ($this->update)(['branch' => 'release', 'auto_deploy' => true])->assertOk();
        expect($this->instance->fresh()->deployment_branch)->toBe('release')
            ->and($this->instance->fresh()->auto_deploy)->toBeTrue();

        ($this->update)(['auto_deploy' => false])->assertOk()->assertJsonPath('data.auto_deploy.enabled', false);
        expect($this->instance->fresh()->auto_deploy)->toBeFalse()
            ->and($this->instance->fresh()->deployment_branch)->toBe('release');
    });

    it('refuses a development Instance', function (): void {
        $this->owner->roles()->where('role', RoleName::AppProd->value)->delete();
        $this->owner->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

        ($this->update)(['auto_deploy' => true])
            ->assertConflict()
            ->assertJsonPath('error.code', 'deployment_config.unavailable');
    });

    it('refuses a body without a field or with a malformed value', function (array $body): void {
        ($this->update)($body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        expect($this->instance->fresh()->auto_deploy)->toBeFalse();
    })->with([
        'empty' => [[]],
        'string flag' => [['auto_deploy' => 'yes']],
        'unknown key' => [['auto_deploy' => true, 'gate' => 'none']],
    ]);

    it('shows development defaults as always automatic and other development Instances as manual', function (string $name, bool $enabled): void {
        $instance = dev935_instance('dev-auto', $name);

        $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
            ->getJson("/api/v1/instances/{$instance->id}")
            ->assertOk()
            ->assertJsonPath('data.auto_deploy.enabled', $enabled)
            ->assertJsonPath('data.auto_deploy.gate', null)
            ->assertJsonPath('data.auto_deploy.warning', null);
    })->with(['default' => ['default', true], 'task workspace' => ['task-1', false]]);
});
