<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\ReadInstanceSmokeContextAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/**
 * Fresh admission and pre-effect evidence. No cached verdict grants write authority.
 *
 * @phpstan-type Intent array{project_id:int,group_id:int,publish_operation_id:int,repository:string,default_branch:string,smoke_workflow:string,pr_number:int,head_sha:string,token:array{'$secret':string,id:int}}
 */
final readonly class DependencyBatchMergeGuard
{
    public function __construct(private GitHubApi $github, private RepositoryPullRequestAccess $access,
        private GitHubActionsReader $reader, private ReadInstanceSmokeContextAction $context, private DependencyBatchContents $contents) {}

    /** @return Intent */
    public function lineage(Task $group, int $publishId, string $head): array
    {
        $group->refresh()->load('project', 'tasks');
        $project = $group->project;
        $policy = config('dependency_merge.projects.'.$project->id);
        $this->check(is_array($policy) && ($policy['enabled'] ?? false) === true, 'dependency_merge.disabled');
        $workflow = $policy['smoke_workflow'] ?? null;
        $this->check(is_string($workflow) && preg_match('/\A\.github\/workflows\/[A-Za-z0-9_-]+\.ya?ml\z/D', $workflow) === 1, 'dependency_merge.smoke_missing');
        $this->check(is_string($project->default_branch) && $project->default_branch !== '', 'dependency_merge.default_branch_changed');
        $this->check(in_array($project->type->value, ['laravel-app', 'symfony-app'], true), 'dependency_merge.app_required');
        $instance = Instance::query()->where('project_id', $project->id)->whereKey($policy['instance_id'] ?? 0)->first();
        $ownership = $instance === null ? null : $this->context->ownership($instance);
        $this->check($ownership?->production === true && $ownership->baseUrls !== [], 'dependency_merge.host_required');
        $this->check($group->parent_id === null && $group->orchestration === TaskOrchestration::External
            && ! in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)
            && ! $group->assistance_requested && $group->tasks->count() === 1, 'dependency_merge.lineage_invalid');
        $child = $group->tasks->sole();
        $services = config('dependency_detection.services_node_id');
        $this->check((is_int($services) || is_string($services)) && $group->created_by_node_id !== null && (string) $group->created_by_node_id === (string) $services
            && ! $child->assistance_requested && ! in_array($child->status, [TaskStatus::Failed, TaskStatus::Cancelled], true)
            && array_any($child->deliverables ?? [], static fn (array $item): bool => ($item['id'] ?? null) === 'package' && ($item['type'] ?? null) === 'review')
            && preg_match('/^Swamp schedule: batch-[a-f0-9]{64}$/m', $group->brief ?? '') === 1, 'dependency_merge.batch_required');
        $publish = Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)->find($publishId);
        $result = $publish === null ? [] : ($publish->result ?? []);
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        $number = $result['pull_request_number'] ?? null;
        $url = $result['pull_request_url'] ?? null;
        $check = TaskCheck::query()->where('task_id', $child->id)->whereKey($publish?->input['check'] ?? 0)->first();
        $this->check($repository !== null && $publish?->status->value === 'succeeded'
            && $check?->kind === TaskCheckKind::Handoff && $check->status === TaskCheckStatus::Passed && $check->commit_sha === $head
            && ($result['commit'] ?? null) === $head && ($result['branch'] ?? null) === 'task-'.$group->id
            && is_int($number) && $number > 0 && $url === 'https://github.com/'.$repository->owner.'/'.$repository->name.'/pull/'.$number
            && $group->pr_url === $url && $group->watched_pr_url === $url && $group->watched_pr_number === $number,
            'dependency_merge.publication_invalid');

        return ['project_id' => $project->id, 'group_id' => $group->id, 'publish_operation_id' => $publishId,
            'repository' => $repository->owner.'/'.$repository->name, 'default_branch' => $project->default_branch,
            'smoke_workflow' => $workflow, 'pr_number' => $number, 'head_sha' => $head, 'token' => ['$secret' => 'github.dependency_merge', 'id' => $project->id]];
    }

    /** @param Intent $intent
     * @return array<string, mixed> */
    public function github(array $intent): array
    {
        try {
            return $this->observe($intent);
        } catch (GitHubActionsUnavailable|GitHubApiException $exception) {
            throw new ResourceOperationException('dependency_merge.evidence_unavailable', 'Fresh GitHub evidence is unavailable.', 503, previous: $exception);
        }
    }

    /** @param Intent $intent
     * @return array<string, mixed> */
    private function observe(array $intent): array
    {
        $repository = GitHubRepository::fromOrigin('https://github.com/'.$intent['repository']);
        assert($repository !== null);
        $path = GitHubActionsReader::repositoryPath($repository);
        $read = $this->access->cachedReadToken($repository);
        $repo = $this->reader->json($path, $read);
        $this->check(($repo['default_branch'] ?? null) === $intent['default_branch'] && ($repo['archived'] ?? null) === false
            && ($repo['allow_squash_merge'] ?? null) === true, 'dependency_merge.default_branch_changed');
        $pr = $this->reader->json($path.'/pulls/'.$intent['pr_number'], $read);
        $this->check(($pr['number'] ?? null) === $intent['pr_number'] && Arr::get($pr, 'head.sha') === $intent['head_sha']
            && Arr::get($pr, 'head.ref') === 'task-'.$intent['group_id']
            && Arr::get($pr, 'head.repo.full_name') === $intent['repository']
            && Arr::get($pr, 'base.repo.full_name') === $intent['repository']
            && Arr::get($pr, 'base.ref') === $intent['default_branch'], 'dependency_merge.pr_changed');
        if (($pr['merged'] ?? null) === true) {
            $this->check(is_string($pr['merge_commit_sha'] ?? null) && preg_match('/\A[0-9a-f]{40}\z/D', $pr['merge_commit_sha']) === 1, 'dependency_merge.receipt_invalid');

            return $pr;
        }
        $this->check(($pr['state'] ?? null) === 'open' && ($pr['draft'] ?? null) === false && ($pr['mergeable'] ?? null) === true
            && in_array($pr['mergeable_state'] ?? null, ['clean', 'has_hooks'], true), 'dependency_merge.pr_not_ready');
        $files = $this->files($pr, $path.'/pulls/'.$intent['pr_number'].'/files', $read);
        $base = Arr::get($pr, 'base.sha');
        $this->check(is_string($base) && preg_match('/\A[0-9a-f]{40}\z/D', $base) === 1, 'dependency_merge.pr_changed');
        $contentsToken = $this->access->readToken($repository);
        foreach ($files as $file) {
            $headContent = $this->content($path, $file, $intent['head_sha'], $contentsToken);
            $baseContent = $this->content($path, $file, $base, $contentsToken);
            $manifest = in_array($file, ['composer.json', 'package.json'], true) ? null : $this->content($path, $file === 'composer.lock' ? 'composer.json' : 'package.json', $intent['head_sha'], $contentsToken);
            $this->contents->validate($file, $headContent, $baseContent, $manifest);
        }
        $actionsToken = $this->reader->token($repository);
        $workflows = $this->reader->json($path.'/actions/workflows?per_page=100', $actionsToken);
        $workflowRows = $this->rows($workflows, 'workflows');
        $smokeIds = [];
        foreach ($workflowRows as $workflow) {
            $this->check(is_int($workflow['id'] ?? null), 'dependency_merge.checks_unreadable');
            if (($workflow['path'] ?? null) === $intent['smoke_workflow'] && ($workflow['state'] ?? null) === 'active') {
                $smokeIds[] = $workflow['id'];
            }
        }
        $this->check(count($smokeIds) === 1, 'dependency_merge.smoke_missing');
        $runs = $this->rows($this->reader->json($path.'/actions/runs?'.http_build_query(['head_sha' => $intent['head_sha'], 'event' => 'pull_request', 'per_page' => 100]), $actionsToken), 'workflow_runs');
        $latest = [];
        foreach ($runs as $run) {
            $this->check(($run['head_sha'] ?? null) === $intent['head_sha'] && ($run['event'] ?? null) === 'pull_request'
                && ($run['head_branch'] ?? null) === 'task-'.$intent['group_id'] && is_int($run['id'] ?? null)
                && is_int($run['workflow_id'] ?? null) && is_int($run['check_suite_id'] ?? null), 'dependency_merge.checks_unreadable');
            $id = $run['workflow_id'];
            if (! isset($latest[$id]) || $latest[$id]['id'] < $run['id']) {
                $latest[$id] = $run;
            }
        }
        $this->check($latest !== [] && array_all($latest, static fn (array $run): bool => ($run['status'] ?? null) === 'completed'
            && ($run['conclusion'] ?? null) === 'success'), 'dependency_merge.checks_not_passed');
        $token = $this->access->checksToken($repository);
        $this->check($token !== null, 'dependency_merge.checks_unreadable');
        $checks = $this->github->checkRuns($token, $repository, $intent['head_sha']);
        $suites = array_column($latest, 'check_suite_id');
        $checks = array_values(array_filter($checks, static fn ($run): bool => in_array($run->checkSuiteId, $suites, true)));
        $this->check(RequiredCheckState::of($checks, $intent['head_sha'], 'Required checks') === RequiredCheckState::Passed, 'dependency_merge.checks_not_passed');
        foreach ($smokeIds as $id) {
            $this->check(isset($latest[$id]), 'dependency_merge.smoke_missing');
            $smoke = array_values(array_filter($checks, static fn ($run): bool => $run->checkSuiteId === $latest[$id]['check_suite_id']));
            $this->check(RequiredCheckState::of($smoke, $intent['head_sha'], 'smoke') === RequiredCheckState::Passed
                || RequiredCheckState::of($smoke, $intent['head_sha'], 'Production smoke / smoke') === RequiredCheckState::Passed, 'dependency_merge.smoke_not_passed');
        }
        $fresh = $this->reader->json($path.'/pulls/'.$intent['pr_number'], $read);
        foreach (['head.sha', 'head.ref', 'head.repo.full_name', 'base.sha', 'base.ref', 'base.repo.full_name', 'state', 'draft', 'merged', 'mergeable', 'mergeable_state', 'changed_files'] as $field) {
            $this->check(Arr::get($fresh, $field) === Arr::get($pr, $field), 'dependency_merge.pr_changed');
        }

        return $pr;
    }

    /** @param array<string, mixed> $pr
     * @return list<string> */
    private function files(array $pr, string $path, #[\SensitiveParameter] string $token): array
    {
        $count = $pr['changed_files'] ?? null;
        // GitHub returns at most 3,000 files. Never accept that ambiguous boundary.
        $this->check(is_int($count) && $count > 0 && $count < 3000, 'dependency_merge.files_unreadable');
        $allowed = DependencyBatchContents::Files;
        $seen = [];
        for ($page = 1; ; $page++) {
            $rows = $this->reader->json($path.'?per_page=100&page='.$page, $token);
            $expected = min(100, max(0, $count - count($seen)));
            $this->check(array_is_list($rows) && count($rows) === $expected, 'dependency_merge.files_unreadable');
            if ($expected === 0) {
                return array_keys($seen);
            }
            foreach ($rows as $file) {
                $this->check(is_array($file) && is_string($file['filename'] ?? null)
                    && ! isset($seen[$file['filename']]), 'dependency_merge.files_unreadable');
                $this->check(in_array($file['filename'], $allowed, true), 'dependency_merge.files_forbidden');
                $this->check(in_array($file['status'] ?? null, ['added', 'modified', 'removed', 'renamed', 'copied', 'changed', 'unchanged'], true), 'dependency_merge.files_unreadable');
                $this->check($file['status'] !== 'removed', 'dependency_merge.lock_removed');
                if (in_array($file['status'], ['renamed', 'copied'], true) || array_key_exists('previous_filename', $file)) {
                    $this->check(is_string($file['previous_filename'] ?? null), 'dependency_merge.files_unreadable');
                    $this->check(in_array($file['previous_filename'], $allowed, true), 'dependency_merge.files_forbidden');
                }
                $seen[$file['filename']] = true;
            }
        }
    }

    private function content(string $path, string $file, string $sha, #[\SensitiveParameter] string $token): string
    {
        $data = $this->reader->json($path.'/contents/'.rawurlencode($file).'?ref='.$sha, $token);
        $this->check(($data['type'] ?? null) === 'file' && ($data['path'] ?? null) === $file
            && ($data['encoding'] ?? null) === 'base64' && is_string($data['content'] ?? null)
            && is_int($data['size'] ?? null), 'dependency_merge.contents_unreadable');
        $decoded = base64_decode($data['content'], true);
        $this->check($decoded !== false && strlen($decoded) === $data['size']
            && base64_encode($decoded) === str_replace(["\r", "\n"], '', $data['content']), 'dependency_merge.contents_unreadable');

        return $decoded;
    }

    /** @param array<array-key, mixed> $data
     * @return list<array<string, mixed>> */
    private function rows(array $data, string $field): array
    {
        $rows = $data[$field] ?? null;
        $this->check(is_array($rows) && array_is_list($rows) && count($rows) < 100 && ($data['total_count'] ?? null) === count($rows)
            && array_all($rows, static fn (mixed $row): bool => is_array($row)), 'dependency_merge.checks_unreadable');

        return array_map(static fn (array $row): array => ValidatedData::object($row), $rows);
    }

    /** @phpstan-assert true $condition */
    private function check(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new ResourceOperationException($code, 'Dependency merge requires current enabled batch, publication and green exact-head CI evidence.', 409);
        }
    }
}
