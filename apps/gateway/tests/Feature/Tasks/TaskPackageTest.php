<?php

declare(strict_types=1);

use App\Data\Tasks\StartTaskPackageData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPackageIntent;
use App\Domain\Tasks\TaskPackageProjection;
use App\Http\Requests\Tasks\StartTaskPackageRequest;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(LazilyRefreshDatabase::class);

it('accepts a package name with an optional version constraint, and never an option', function (string $manager, string $package, bool $valid): void {
    $rules = StartTaskPackageRequest::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['manager' => $manager, 'package' => $package]))->rules();

    expect(Validator::make(['manager' => $manager, 'package' => $package], $rules)->passes())->toBe($valid);
})->with([
    'composer name' => ['composer', 'acme/ui', true],
    'composer constraint' => ['composer', 'acme/ui:^1.2', true],
    'npm scoped' => ['npm', '@acme/ui', true],
    'npm version' => ['npm', '@acme/ui@2.1.0', true],
    'npm plain' => ['npm', 'acme-ui', true],
    'npm GitHub shorthand' => ['npm', 'acme/ui', false],
    'npm URL' => ['npm', 'https://evil.example/x.tgz', false],
    'npm file' => ['npm', 'file:../x', false],
    'npm Git source' => ['npm', 'github:acme/ui', false],
    'composer without a vendor' => ['composer', 'ui', false],
    'composer URL' => ['composer', 'https://evil.example/x.zip', false],
    'an option' => ['npm', '--registry=https://evil.example', false],
    'a short flag' => ['npm', '-g', false],
    'a long flag' => ['npm', '--global', false],
    'a dashed scope' => ['npm', '@-x/y', false],
    'a shell word' => ['composer', 'acme/ui; id', false],
    'a space' => ['npm', 'acme ui', false],
    'a newline' => ['composer', "acme/ui\nid", false],
    'an unknown manager' => ['pip', 'acme', false],
    'empty' => ['npm', '', false],
]);

it('refuses a package install for a group that has ended, has no workspace or is busy', function (): void {
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $data = StartTaskPackageData::from(['manager' => 'composer', 'package' => 'acme/ui']);
    $group = fn (TaskGroupStatus $status): Task => Task::query()->create(['project_id' => $project->id, 'title' => 'Package', 'brief' => 'Package it', 'status' => $status]);
    $start = fn (Task $subject) => fn () => app(TaskPackageIntent::class)->start($subject, $data, 'test', null);
    $busy = $group(TaskGroupStatus::Running);
    Operation::query()->create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $busy->id, 'node_id' => null, 'status' => OperationStatus::Running, 'input' => []]);

    expect($start($group(TaskGroupStatus::Cancelled)))->toThrow(ResourceOperationException::class, 'has ended')
        ->and($start($group(TaskGroupStatus::Running)))->toThrow(ResourceOperationException::class, 'owned task VM workspace')
        ->and($start($busy))->toThrow(ResourceOperationException::class, 'Another Task Operation');
});

it('records the manager, the package and the token outcome of a finished install, and a fixed code for a failed one', function (): void {
    $operation = Operation::query()->create(['kind' => 'tasks.package', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => null,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'], 'input' => ['manager' => 'composer', 'package' => 'acme/ui']]);
    $failed = Operation::query()->create(['kind' => 'tasks.package', 'target_type' => 'task_group', 'target_id' => 2, 'node_id' => null,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'], 'input' => ['manager' => 'npm', 'package' => '@acme/ui']]);
    $times = ['started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'];

    $done = app(TaskPackageProjection::class)->apply($operation, [...$times, 'status' => 'succeeded', 'result' => ['dependency_token' => ['state' => 'revoked']]]);
    $broken = app(TaskPackageProjection::class)->apply($failed, [...$times, 'status' => 'failed', 'result' => [], 'failure' => ['failed_step' => 'Install the package', 'detail' => 'exit 1', 'stderr_recorded' => false]]);

    expect($done->status)->toBe(OperationStatus::Succeeded)->and($done->result)->toBe(['manager' => 'composer', 'package' => 'acme/ui', 'dependency_token' => ['state' => 'revoked']])
        ->and($broken->status)->toBe(OperationStatus::Failed)->and($broken->error_code)->toBe('tasks.package_failed');
});

it('names the reason of a token that was not revoked, and never reads a missing report as a success', function (string $case, array $reported, array $expected): void {
    $operation = Operation::query()->create(['kind' => 'tasks.package', 'target_type' => 'task_group', 'target_id' => 3, 'node_id' => null,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'], 'input' => ['manager' => 'composer', 'package' => 'acme/ui']]);
    $times = ['started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'];

    $done = app(TaskPackageProjection::class)->apply($operation, [...$times, 'status' => 'succeeded', 'result' => $reported]);

    expect($done->status)->toBe(OperationStatus::Succeeded)->and($done->result['dependency_token'])->toBe($expected);
})->with([
    'GitHub answered 401' => ['none', ['dependency_token' => ['state' => 'none', 'reason' => 'GitHub holds no valid setup token.']], ['state' => 'none', 'reason' => 'GitHub holds no valid setup token.']],
    'the revoke failed' => ['unrevoked', ['dependency_token' => ['state' => 'unrevoked', 'reason' => 'GitHub answered HTTP 503 to the revoke.']], ['state' => 'unrevoked', 'reason' => 'GitHub answered HTTP 503 to the revoke.']],
    'nothing reported' => ['missing', [], ['state' => 'unrevoked', 'reason' => 'The revoke result was not recorded.']],
    'an unknown state' => ['unknown', ['dependency_token' => ['state' => 'fine']], ['state' => 'unrevoked', 'reason' => 'The revoke result was not recorded.']],
]);

// The admission names the vault keys of the dependency configuration and mints nothing.
arch('the admission of a package install uses no GitHub access')
    ->expect(TaskPackageIntent::class)
    ->not->toUse([GitHubApi::class, RepositoryPullRequestAccess::class, SwampCommandStep::class]);
