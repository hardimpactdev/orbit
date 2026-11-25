<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Actions\Projects\ListProjectsAction;
use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Extensions\ExtensionStore;
use App\Models\Node;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Infrastructure\AiRouterThreadClassifier;
use Orbit\Extensions\Conn\Infrastructure\ConnThreadOrchestration;
use Orbit\Extensions\Conn\Infrastructure\HttpT3ServerClient;
use Orbit\Extensions\Conn\Jobs\ClassifyConnThreadJob;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnThread;
use Orbit\Extensions\Conn\Models\ConnThreadClassification;

/** Files a new host General thread once, without reading it again on later turns. */
final readonly class ConnThreadLibrarian
{
    public function __construct(
        private ExtensionStore $extensions,
        private HttpT3ServerClient $http,
        private ConnThreadOrchestration $orchestration,
        private ListProjectsAction $projects,
        private AiRouterThreadClassifier $classifier,
    ) {}

    /** @param array<array-key, mixed> $shell */
    public function discover(ConnEnvironment $environment, array $shell, CarbonImmutable $observedAt): void
    {
        if (! AiRouterThreadClassifier::configured() || ! is_array($shell['projects'] ?? null) || ! is_array($shell['threads'] ?? null)) {
            return;
        }
        $cutoff = $this->cutoff($environment, $observedAt);
        $roots = [];
        foreach ($shell['projects'] as $project) {
            if (is_array($project) && is_string($project['id'] ?? null) && is_string($project['workspaceRoot'] ?? null)) {
                $roots[$project['id']] = $project['workspaceRoot'];
            }
        }
        $candidates = [];
        foreach ($shell['threads'] as $thread) {
            if (self::unassignedTopLevel($thread) && is_string($thread['id'] ?? null) && is_string($thread['projectId'] ?? null)
                && isset($roots[$thread['projectId']]) && is_string($thread['latestUserAuthoredMessageAt'] ?? null)
                && is_string($thread['createdAt'] ?? null) && CarbonImmutable::parse($thread['createdAt'])->getTimestampMs() >= $cutoff) {
                $candidates[$thread['id']] = $thread['projectId'];
            }
        }
        if ($candidates === []) {
            return;
        }
        $completed = ConnThreadClassification::query()->where('conn_environment_id', $environment->id)
            ->whereNotNull('completed_at')->whereIn('thread_id', array_keys($candidates))->get(['thread_id'])
            ->map(static fn (ConnThreadClassification $classification): string => $classification->thread_id)->all();
        foreach (array_diff_key($candidates, array_fill_keys($completed, true)) as $threadId => $projectId) {
            GatewaySchedule::dispatch(new ClassifyConnThreadJob($environment->id, (string) $threadId, $projectId,
                $roots[$projectId]));
        }
    }

    public function classify(ClassifyConnThreadJob $job): void
    {
        if (! $this->extensions->enabled('conn') || ! AiRouterThreadClassifier::configured()) {
            return;
        }
        $environment = ConnEnvironment::query()->whereNull('instance_id')->with('node')->find($job->environmentId);
        if ($environment === null || ! $environment->node instanceof Node) {
            return;
        }
        $key = ['conn_environment_id' => $environment->id, 'thread_id' => $job->threadId];
        $receipt = $this->receipt($job);
        if ($receipt->completed_at !== null) {
            return;
        }
        if (! $this->http->supportsThreadFiling($environment->url)) {
            $this->complete($receipt);

            return;
        }
        $projection = $this->projection($environment, $job->threadId);
        $thread = $projection['thread'] ?? null;
        if (! self::unassignedTopLevel($thread) || ($thread['projectId'] ?? null) !== $job->connProjectId) {
            $this->complete($receipt);

            return;
        }
        $scratch = $this->orchestration->scratchWorkspaceRoot($environment);
        if ($scratch === null || rtrim($job->connWorkspaceRoot, '/') !== rtrim($scratch, '/')) {
            $this->complete($receipt);

            return;
        }
        $projects = $this->projects->handle($environment->node)->keyBy('id');
        $answer = $receipt->result;
        if ($answer === null) {
            $first = $this->firstAuthoredMessage($projection['messages'] ?? null);
            if ($first === null || ! is_string($thread['title'] ?? null)) {
                $this->complete($receipt);

                return;
            }
            $answer = $this->classifier->classify($thread['title'], $first, array_values($projects->map(static fn (Project $project): array => [
                'id' => $project->id,
                'slug' => $project->slug,
                'name' => $project->name,
                'description' => $project->repository_identity !== null ? 'Repository '.$project->repository_identity
                    : ($project->node !== null ? 'Work for host '.$project->node->name : 'General work without a repository'),
            ])->all()));
            $receipt->result = $answer;
            $receipt->save();
        }
        if ($answer['result'] === 'existing' && ($project = $projects->get($answer['project_id'])) instanceof Project) {
            $current = $this->projection($environment, $job->threadId)['thread'] ?? null;
            if (self::unassignedTopLevel($current) && ($current['projectId'] ?? null) === $job->connProjectId) {
                $this->orchestration->file($environment, $job->threadId, $project->id, $project->slug);
                ConnThread::hostQuery()->where($key)->update(['orbit_project_id' => $project->id]);
            }
        }
        $this->complete($receipt);
    }

    public function giveUp(ClassifyConnThreadJob $job, string $errorCode): void
    {
        if (! ConnEnvironment::query()->whereKey($job->environmentId)->exists()) {
            return;
        }
        $receipt = $this->receipt($job);
        if ($receipt->completed_at === null) {
            $receipt->result = ['result' => 'given_up', 'error_code' => $errorCode];
            $this->complete($receipt);
        }
    }

    /** @phpstan-assert-if-true array<array-key, mixed> $thread */
    private static function unassignedTopLevel(mixed $thread): bool
    {
        $lineage = is_array($thread) ? ($thread['lineage'] ?? null) : null;

        return is_array($thread) && is_array($lineage) && array_key_exists('parentThreadId', $lineage)
            && $lineage['parentThreadId'] === null && ($thread['delegatedFrom'] ?? null) === null && ($thread['archivedAt'] ?? null) === null
            && ! array_key_exists('orbitProject', $thread);
    }

    private function firstAuthoredMessage(mixed $messages): ?string
    {
        $first = null;
        foreach (is_array($messages) ? $messages : [] as $message) {
            if (! is_array($message) || ($message['role'] ?? null) !== 'user' || ($message['createdBy'] ?? null) !== 'user'
                || ! is_string($message['createdAt'] ?? null) || ! is_string($message['text'] ?? null)) {
                continue;
            }
            $createdAt = CarbonImmutable::parse($message['createdAt']);
            if ($first === null || $createdAt->isBefore($first['createdAt'])) {
                $first = ['createdAt' => $createdAt, 'text' => $message['text']];
            }
        }

        return $first['text'] ?? null;
    }

    /** @return array<array-key, mixed>|null */
    private function projection(ConnEnvironment $environment, string $threadId): ?array
    {
        return $this->http->threadProjection($environment->url, $environment->admin_session, $threadId);
    }

    private function complete(ConnThreadClassification $receipt): void
    {
        $receipt->completed_at = now();
        $receipt->save();
    }

    private function receipt(ClassifyConnThreadJob $job): ConnThreadClassification
    {
        return ConnThreadClassification::query()->firstOrNew(['conn_environment_id' => $job->environmentId, 'thread_id' => $job->threadId]);
    }

    private function cutoff(ConnEnvironment $environment, CarbonImmutable $observedAt): int
    {
        $watermarks = DB::table('conn_librarian_watermarks');
        $stored = $watermarks->where('conn_environment_id', $environment->id)->value('started_at_ms');
        if (is_numeric($stored)) {
            return (int) $stored;
        }
        $startedAt = $observedAt->getTimestampMs();
        $watermarks->insert(['conn_environment_id' => $environment->id, 'started_at_ms' => $startedAt]);

        return $startedAt;
    }
}
