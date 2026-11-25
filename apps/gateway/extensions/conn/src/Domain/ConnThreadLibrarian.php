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
     * The push: the host's Conn Server recorded a thread, which stands for its first user message. A top-level General thread with no
     * Orbit filing, created at or after the host's cutoff, in the scratch project, with no completed receipt, gets one classification
     * job. One outside the scratch project gets a completed receipt, so a later push does not ask the server again.
     */
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
        $general = self::unassignedTopLevel($thread);
        if (! is_array($thread) || ! is_string($thread['createdAt'] ?? null)) {
            return;
        }
        $createdAt = CarbonImmutable::parse($thread['createdAt'])->getTimestampMs();
        $projectId = $thread['projectId'] ?? null;
        $root = collect(is_array($shell['projects'] ?? null) ? $shell['projects'] : [])
            ->first(static fn (mixed $project): bool => is_array($project) && is_string($projectId) && ($project['id'] ?? null) === $projectId);
        $root = is_array($root) ? ($root['workspaceRoot'] ?? null) : null;
        if ($createdAt < $this->cutoff($environment, $createdAt) || ! $general || ! is_string($projectId) || ! is_string($root)) {
            return;
        }
        $classification = new ClassifyConnThreadJob($environment->id, $job->threadId, $projectId, $root);
        $receipt = $this->receipt($classification);
        if ($receipt->completed_at !== null) {
            return;
        }
        if (self::inScratch($root, $this->orchestration->scratchWorkspaceRoot($environment))) {
            GatewaySchedule::dispatch($classification);
        } else {
            $this->complete($receipt);
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
        if (! $this->http->supportsThreadFiling($environment->url)
            || ! self::inScratch($job->connWorkspaceRoot, $this->orchestration->scratchWorkspaceRoot($environment))) {
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

    /** The host's cutoff: the creation time of the first thread a push brought, stored once, so an older thread is never classified. */
    private function cutoff(ConnEnvironment $environment, int $createdAtMs): int
    {
        DB::table('conn_librarian_watermarks')->insertOrIgnore(['conn_environment_id' => $environment->id, 'started_at_ms' => $createdAtMs]);

        $stored = DB::table('conn_librarian_watermarks')->where('conn_environment_id', $environment->id)->value('started_at_ms');

        return is_numeric($stored) ? (int) $stored : $createdAtMs;
    }
}
