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
use Illuminate\Support\Sleep;
use Orbit\Extensions\Conn\Infrastructure\AiRouterThreadClassifier;
use Orbit\Extensions\Conn\Infrastructure\ConnThreadOrchestration;
use Orbit\Extensions\Conn\Infrastructure\HttpT3ServerClient;
use Orbit\Extensions\Conn\Jobs\ClassifyConnThreadJob;
use Orbit\Extensions\Conn\Jobs\DiscoverConnThreadJob;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnThread;
use Orbit\Extensions\Conn\Models\ConnThreadClassification;

/** Files a new host General thread once, without reading it again on later turns. */
final readonly class ConnThreadLibrarian
{
    /** Reads of a thread whose first user message is not readable yet, and the gap between them. */
    public const int MessageReads = 4;

    public const int MessageReadGapSeconds = 2;

    public function __construct(
        private ExtensionStore $extensions,
        private HttpT3ServerClient $http,
        private ConnThreadOrchestration $orchestration,
        private ListProjectsAction $projects,
        private AiRouterThreadClassifier $classifier,
    ) {}

    /**
     * The poll: every thread of one host shell that lists a user-authored message.
     *
     * @param  array<array-key, mixed>  $shell
     */
    public function discover(ConnEnvironment $environment, array $shell, CarbonImmutable $observedAt): void
    {
        $this->queueEligible($environment, $shell, static fn (array $thread): bool => is_string($thread['latestUserAuthoredMessageAt'] ?? null), $observedAt);
    }

    /** The push: the one thread that the host's Conn Server recorded, which stands for its first user message. */
    public function pushed(DiscoverConnThreadJob $job): void
    {
        if (! $this->extensions->enabled('conn') || ! AiRouterThreadClassifier::configured()) {
            return;
        }
        $environment = ConnEnvironment::query()->whereNull('instance_id')->whereNotNull('node_id')->find($job->environmentId);
        if ($environment === null) {
            return;
        }
        $shell = $this->http->shell($environment->url, $environment->admin_session);
        $this->queueEligible($environment, $shell, static fn (array $thread): bool => ($thread['id'] ?? null) === $job->threadId, CarbonImmutable::now());
    }

    /**
     * The librarian's eligibility, for the poll and the push alike: a top-level General thread with no Orbit filing, created after the
     * host's cutoff, in the scratch project, whose receipt is not completed. Each eligible thread gets one classification job; one
     * outside the scratch project gets a completed receipt.
     *
     * @param  array<array-key, mixed>  $shell
     * @param  \Closure(array<array-key, mixed>): bool  $selected
     */
    private function queueEligible(ConnEnvironment $environment, array $shell, \Closure $selected, CarbonImmutable $observedAt): void
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
            if (self::unassignedTopLevel($thread) && $selected($thread) && is_string($thread['id'] ?? null) && is_string($thread['projectId'] ?? null)
                && isset($roots[$thread['projectId']])
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
        $candidates = array_diff_key($candidates, array_fill_keys($completed, true));
        $scratch = $candidates === [] ? null : $this->orchestration->scratchWorkspaceRoot($environment);
        foreach ($candidates as $threadId => $projectId) {
            $job = new ClassifyConnThreadJob($environment->id, (string) $threadId, $projectId, $roots[$projectId]);
            if (self::inScratch($roots[$projectId], $scratch)) {
                GatewaySchedule::dispatch($job);
            } else {
                // A thread outside the scratch project is refused once, so later polls do not ask the server again.
                $this->complete($this->receipt($job));
            }
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
        if (! self::inScratch($job->connWorkspaceRoot, $this->orchestration->scratchWorkspaceRoot($environment))) {
            $this->complete($receipt);

            return;
        }
        $projects = $this->projects->handle($environment->node)->keyBy('id');
        $answer = $receipt->result;
        if ($answer === null) {
            // A push can arrive before its first message is readable: read again a few times, and leave the receipt open when it is not.
            for ($read = 1; ! self::authored($projection['messages'] ?? null) && $read < self::MessageReads; $read++) {
                Sleep::for(self::MessageReadGapSeconds)->seconds();
                $projection = $this->projection($environment, $job->threadId);
            }
            if (! self::authored($projection['messages'] ?? null)) {
                return;
            }
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

    private static function inScratch(string $root, ?string $scratch): bool
    {
        return $scratch !== null && rtrim($root, '/') === rtrim($scratch, '/');
    }

    private static function authored(mixed $messages): bool
    {
        foreach (is_array($messages) ? $messages : [] as $message) {
            if (is_array($message) && ($message['role'] ?? null) === 'user' && ($message['createdBy'] ?? null) === 'user') {
                return true;
            }
        }

        return false;
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
