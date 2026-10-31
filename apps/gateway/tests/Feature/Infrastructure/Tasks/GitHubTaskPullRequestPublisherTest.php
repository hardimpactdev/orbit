<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

function publisher_group(string $checkout, string $repository = 'git@github.com:acme/shop.git'): Task
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => $repository, 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'publish-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.150', 'wireguard_ip' => '10.44.0.150', 'user' => 'orbit']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Export orders', 'brief' => 'Add an export.', 'status' => 'reviewing']);
    $instance = Instance::query()->create(['task_workspace_owner_id' => $group->id, 'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-7', 'checkout_path' => $checkout, 'branch' => 'task-7', 'status' => 'source_resolved']);
    $group->taskable()->associate($instance);
    $group->save();

    return test_vm_group($group);
}

it('deletes the handoff branch on origin, and counts a branch or repository that is already gone as deleted', function (int $status, ?string $failure): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_publish'], 201),
        'https://api.github.com/repos/acme/shop/git/refs/heads/*' => Http::response($status === 422 ? ['message' => 'Reference does not exist'] : null, $status),
    ]);
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    $delete = fn () => app(GitHubTaskPullRequestPublisher::class)->deleteHandoffBranch($group);

    $failure === null ? $delete() : expect($delete)->toThrow(TaskPullRequestException::class, $failure);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/shop/git/refs/heads/task-'.$group->id.'-handoff');
})->with([
    'deleted' => [204, null],
    'already gone' => [422, null],
    'repository gone' => [404, null],
    'refused' => [403, 'The handoff branch could not be deleted: GitHub refused the request.'],
    'unavailable' => [502, 'The handoff branch could not be deleted: GitHub could not be reached or refused the Project credential.'],
]);
