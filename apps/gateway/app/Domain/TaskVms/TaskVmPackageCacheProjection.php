<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\TaskVmInputs;
use App\Models\Node;
use App\Models\Operation;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Pinned agent batches at the run start, then one terminal projection of the batch replies against the admitted lockfile snapshots. */
final readonly class TaskVmPackageCacheProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    /** One agent request stays below the agent's 4 MiB envelope limit. */
    public const int BatchArtifacts = 4096;

    /** The batch requests travel in the run inputs and their replies in its history, which the shared reader reads once
     * within its 8 MiB bound: about 1.4 MB of request and 0.7 MB of reply per full batch. */
    public const int MaximumBatches = 2;

    /** Each batch request runs inside this agent deadline; the step's literal `timeout 1530` bounds it on the host. */
    public const int BatchDeadlineMilliseconds = 1_500_000;

    public const string FillStep = 'Fill the package cache';

    public function __construct(private TaskVmSettings $settings) {}

    /** Fixed captures: the full stdout of each batch's fill step.
     * @return array<string,array{job:string,step:string,field:string}>
     */
    public static function captures(): array
    {
        $captures = [];
        for ($batch = 0; $batch < self::MaximumBatches; $batch++) {
            $captures['fill_'.$batch] = ['job' => 'fill', 'step' => self::FillStep.'-'.$batch, 'field' => 'stdout'];
        }

        return $captures;
    }

    /** @param list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}> $artifacts
     * @return list<list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}>>
     */
    public static function batches(array $artifacts): array
    {
        $batches = array_chunk($artifacts, self::BatchArtifacts);

        return $batches === [] ? [[]] : $batches;
    }

    /** Public intent only: the host's pinned SSH target, each batch's agent request up to its token, and the integrity conflicts. */
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.fill_package_cache' || $operation->target_type !== 'task_vm_cache'
            || $operation->node_id !== $operation->target_id || ($input['host_node_id'] ?? null) !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Cache intent requires its exact admitted host.', 422);
        }
        $this->settings->host(ValidatedData::integer($operation->node_id));
        $node = Node::query()->findOrFail($operation->node_id);
        $pin = app(AgentDelivery::class)->pin($node);
        $requests = [];
        foreach (self::batches(TaskVmInputs::artifacts($input['artifacts'])) as $items) {
            // The agent reads one JSON line; the step appends the vault's github_token member and request_end.
            $request = json_encode(['agent_sha256' => $pin, 'deadline_ms' => self::BatchDeadlineMilliseconds, 'input' => [
                'operation' => 'fill', 'artifacts' => $items, 'max_bytes' => ValidatedData::integer($input['max_bytes']), 'github_token' => null,
            ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $requests[] = substr($request, 0, -strlen('"github_token":null}}'));
        }

        return ['intent' => [
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($node),
            'batches' => array_keys($requests),
            'requests' => $requests,
            'request_end' => "}}\n",
            'conflicts' => (object) ValidatedData::stringMap($input['conflicts']),
        ], 'execution_secrets' => []];
    }

    /** The vault holds only the request's `github_token` member, with the minted token or null; the public request bytes stay in the inputs.
     * Serve redacts each vault value it reads with a pattern built from the value, so the value must stay small and distinctive.
     */
    public function workflowInput(Operation $operation): array
    {
        $input = $this->input($operation);
        $token = $this->githubToken($operation, TaskVmInputs::artifacts($operation->input['artifacts']));
        if ($token !== null && preg_match('/\A[A-Za-z0-9_]{1,255}\z/D', $token) !== 1) {
            throw new ResourceOperationException('task_vm.package_cache_failed', 'The GitHub installation token has an unexpected form.', 502);
        }

        return [...$input, 'execution_secrets' => ['github_token' => app(SwampCommandStep::class)->executionCopy(
            'orbit-control-inputs', 'task-vm-cache-fill-github-'.$operation->node_id, '"github_token":'.json_encode($token, JSON_THROW_ON_ERROR))]];
    }

    /** A metadata-read installation token for Composer archives on GitHub; npm archives need none.
     * @param  list<array{manager:string,package:string,version:string,url:string,integrity:string|null,key:string}>  $artifacts
     */
    private function githubToken(Operation $operation, array $artifacts): ?string
    {
        if (! array_any($artifacts, static fn (array $artifact): bool => $artifact['manager'] === 'composer') || app(GitHubAppStore::class)->credentials() === null) {
            return null;
        }
        $projects = array_column(TaskVmInputs::snapshots($operation->input['snapshots']), 'project_id');
        foreach (Project::query()->whereIn('id', $projects)->orderBy('id')->get() as $project) {
            $repository = GitHubRepository::fromOrigin($project->repository_url);
            if ($repository instanceof GitHubRepository) {
                try {
                    return app(RepositoryPullRequestAccess::class)->metadataToken($repository);
                } catch (GitHubApiException) {
                    continue;
                }
            }
        }

        return null;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            if (! $status->terminal()) {
                throw new ResourceOperationException('operation.result_invalid', 'The cache result needs a terminal native outcome.', 422);
            }
            $native = ValidatedData::object($data['result'] ?? []);
            $code = in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true) ? TaskVmError::PackageCacheFailed->value : null;
            if (! $operation->status->terminal()) {
                [$hashes, $failures, $first] = $this->replies($operation, $native);
                if ($hashes !== [] || $failures !== []) {
                    $this->inventory($operation, $hashes, $failures, $first);
                }
            }

            return app(DirectOperation::class)->project($operation, [...$data, 'error_code' => $code],
                $code !== null ? ['error_code' => $code] : ($status === OperationStatus::Succeeded ? ['ready' => true] : []));
        });
    }

    /** Batch replies the run recorded; a reply that names an archive outside its batch is refused.
     * @param  array<string,mixed>  $native
     * @return array{0:array<string,string>,1:array<string,string>,2:bool}
     */
    private function replies(Operation $operation, array $native): array
    {
        $batches = self::batches(TaskVmInputs::artifacts($operation->input['artifacts']));
        $hashes = [];
        $failures = [];
        foreach ($batches as $batch => $items) {
            $stdout = $native['fill_'.$batch] ?? null;
            if (! is_string($stdout)) {
                continue;
            }
            $reply = json_decode($stdout, true);
            if (! is_array($reply) || ($reply['ok'] ?? null) !== true || ! is_array($reply['data'] ?? null)) {
                continue;
            }
            $batchHashes = ValidatedData::stringMap($reply['data']['hashes'] ?? []);
            $batchFailures = ValidatedData::stringMap($reply['data']['failures'] ?? []);
            $keys = array_column($items, 'key');
            if (array_diff([...array_keys($batchHashes), ...array_keys($batchFailures)], $keys) !== []
                || array_any($batchHashes, static fn (string $hash): bool => preg_match('/\Asha256-[a-f0-9]{64}\z/D', $hash) !== 1)) {
                throw new ResourceOperationException('operation.result_invalid', 'A cache batch reply names an archive outside its batch.', 422);
            }
            $hashes = [...$hashes, ...$batchHashes];
            $failures = [...$failures, ...array_map(static fn (string $reason): string => substr($reason, 0, 160), $batchFailures)];
        }

        return [$hashes, [...$failures, ...ValidatedData::stringMap($operation->input['conflicts'])], isset($native['fill_0'])];
    }

    /** @param array<string,string> $hashes
     * @param  array<string,string>  $failures
     */
    private function inventory(Operation $operation, array $hashes, array $failures, bool $first): void
    {
        foreach ($failures as $key => $reason) {
            Log::warning('Task VM package cache artifact failed.', ['host_node_id' => $operation->node_id, 'archive_id' => $key,
                'reason' => new CommandActivityInputSanitizer()->redactText($reason)]);
        }
        foreach (TaskVmInputs::snapshots($operation->input['snapshots']) as $snapshot) {
            $current = PackageCacheSnapshot::query()->lockForUpdate()->find($snapshot['project_id']);
            if (! $current instanceof PackageCacheSnapshot || $current->blobs !== $snapshot['blobs'] || $current->artifacts !== $snapshot['artifacts']) {
                continue;
            }
            $entries = PackageCacheEntry::query()->where('project_id', $current->project_id)->where('host_node_id', $operation->node_id);
            if ($first) {
                $entries->delete();
            } else {
                $entries->whereIn('key', [...array_keys($hashes), ...array_keys($failures)])->delete();
            }
            foreach ($snapshot['artifacts'] as $artifact) {
                if (isset($hashes[$artifact['key']])) {
                    PackageCacheEntry::query()->create(['project_id' => $current->project_id, 'host_node_id' => $operation->node_id,
                        ...Arr::only($artifact, ['manager', 'package', 'version', 'key']), 'integrity' => $hashes[$artifact['key']]]);
                }
            }
            $complete = true;
            foreach ($this->settings->hosts as $host) {
                $entries = PackageCacheEntry::query()->where('project_id', $current->project_id)->where('host_node_id', $host->nodeId)
                    ->get()->keyBy(static fn (PackageCacheEntry $entry): string => $entry->package.' '.$entry->version.' '.$entry->key);
                foreach ($snapshot['artifacts'] as $artifact) {
                    if (! $entries->has($artifact['package'].' '.$artifact['version'].' '.$artifact['key'])) {
                        $complete = false;
                        break 2;
                    }
                }
            }
            $current->update(['fill_pending' => ! $complete]);
        }
    }
}
