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
     * The poll, the fallback for a push that never came: every thread the host's shell lists with a user-authored message.
     *
     * @param  array<array-key, mixed>  $shell
     */
    public function discover(ConnEnvironment $environment, array $shell, CarbonImmutable $observedAt): void
    {
        if (! AiRouterThreadClassifier::configured() || ! is_array($shell['threads'] ?? null)) {
            return;
        }
        $this->admit($environment, $shell, array_filter($shell['threads'],
            static fn (mixed $thread): bool => is_array($thread) && is_string($thread['latestUserAuthoredMessageAt'] ?? null)),
            $observedAt->getTimestampMs());
    }

    /** The push: the host's Conn Server recorded a thread, which stands for its first user message. */
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
        $thread = collect(is_array($shell['threads'] ?? null) ? $shell['threads'] : [])
            ->first(static fn (mixed $thread): bool => is_array($thread) && ($thread['id'] ?? null) === $job->threadId);
        if (is_array($thread) && is_string($thread['createdAt'] ?? null)) {
            // A host's first push sets its cutoff at the pushed thread's creation, so that thread is in scope and an older one is not.
            $this->admit($environment, $shell, [$thread], CarbonImmutable::parse($thread['createdAt'])->getTimestampMs());
        }
    }

    /**
     * The one eligibility rule of push and poll. A top-level General thread with no Orbit filing, created at or after the host's cutoff,
     * whose Conn project is the scratch project and that has no completed receipt, gets the classification job, unique per thread. One
     * outside the scratch project gets a completed receipt, so it is not asked about again.
     *
     * @param  array<array-key, mixed>  $shell
     * @param  array<array-key, mixed>  $threads
     */
    private function admit(ConnEnvironment $environment, array $shell, array $threads, int $startedAtMs): void
    {
        $cutoff = $this->cutoff($environment, $startedAtMs);
        $roots = [];
        foreach (is_array($shell['projects'] ?? null) ? $shell['projects'] : [] as $project) {
            if (is_array($project) && is_string($project['id'] ?? null) && is_string($project['workspaceRoot'] ?? null)) {
                $roots[$project['id']] = $project['workspaceRoot'];
            }
        }
        $candidates = [];
        foreach ($threads as $thread) {
            if (self::unassignedTopLevel($thread) && is_string($thread['id'] ?? null) && is_string($thread['projectId'] ?? null)
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
        if ($candidates === []) {
            return;
        }
        $scratch = $this->orchestration->scratchWorkspaceRoot($environment);
        foreach ($candidates as $threadId => $projectId) {
            $job = new ClassifyConnThreadJob($environment->id, (string) $threadId, $projectId, $roots[$projectId]);
            if (self::inScratch($job->connWorkspaceRoot, $scratch)) {
                GatewaySchedule::dispatch($job);
            } else {
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
        $projects = $this->projects->handle($environment->node)->keyBy('id');
        $answer = $receipt->result;
        if ($answer === null) {
            // A push can arrive before the thread or its first message is readable: read again a few times, each read judged on its own
            // thread, and leave the receipt open when the message stays unreadable.
            for ($read = 1; ; $read++) {
                $projection = $this->projection($environment, $job->threadId);
                $thread = $projection['thread'] ?? null;
                if ($projection !== null && (! self::unassignedTopLevel($thread) || ($thread['projectId'] ?? null) !== $job->connProjectId
                    || ! is_string($thread['title'] ?? null))) {
                    $this->complete($receipt);

                    return;
                }
                $first = $this->firstAuthoredMessage($projection['messages'] ?? null);
                if ($first !== null || $read === self::MessageReads) {
                    break;
                }
                Sleep::for(self::MessageReadGapSeconds)->seconds();
            }
            if ($first === null || ! is_array($thread) || ! is_string($thread['title'] ?? null)) {
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
            // A retry after a filing whose reply was lost finds the thread in this answer's Project: it records it without filing again.
            $orbitProject = is_array($current) ? ($current['orbitProject'] ?? null) : null;
            $filed = is_array($orbitProject) && ($orbitProject['id'] ?? null) === $project->id;
            if ($filed || (self::unassignedTopLevel($current) && ($current['projectId'] ?? null) === $job->connProjectId)) {
                if (! $filed) {
                    $this->orchestration->file($environment, $job->threadId, $project->id, $project->slug);
                }
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

    /** The host's permanent cutoff, stored once by its first librarian pass, push or poll: an older thread is never classified. */
    private function cutoff(ConnEnvironment $environment, int $startedAtMs): int
    {
        DB::table('conn_librarian_watermarks')->insertOrIgnore(['conn_environment_id' => $environment->id, 'started_at_ms' => $startedAtMs]);

        $stored = DB::table('conn_librarian_watermarks')->where('conn_environment_id', $environment->id)->value('started_at_ms');

        return is_numeric($stored) ? (int) $stored : $startedAtMs;
    }
}
