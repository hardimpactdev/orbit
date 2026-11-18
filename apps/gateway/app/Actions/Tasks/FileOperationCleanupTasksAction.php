<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\CreateTaskGroupData;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Operations\OperationCleanupReview;
use App\Domain\Operations\OperationVersions;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\GatewayRelease;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class FileOperationCleanupTasksAction
{
    public function __construct(
        private CreateTaskGroupAction $tasks,
        private OperationCleanupReview $reviews,
    ) {}

    /**
     * Runs in the release workflow's cleanup step, under the release lock, with the serving release's code after it
     * verified. No switch can interleave. Rows that cannot file yet stay unclaimed for the next verified release.
     *
     * @param  list<array{kind:string,version:int,eligible:bool,outstanding:int}>  $versions
     */
    public function execute(GatewayRelease $live, GatewayReleaseLayout $layout, array $versions): int
    {
        if (! app(TaskExtensionState::class)->enabled()
            || ! in_array($live->commit(), $layout->observe($layout->applicationPath)->verified, true)) {
            return 0;
        }
        $pending = DB::table('operation_cleanup_reviews as r')->join('gateway_releases as g', 'g.id', '=', 'r.release_id')
            ->whereIn('g.outcome', ['verified', 'reconciled'])->whereNull('r.processed_at');
        $eligible = [];
        foreach ($versions as $version) {
            $eligible[$version['kind']][$version['version']] = $version['eligible'];
        }
        $filed = 0;
        $failures = [];
        $policies = OperationVersions::policies();
        foreach ($pending->orderBy('r.release_id')->orderBy('r.id')->get(['r.*', 'g.sha', 'g.requested']) as $row) {
            // Leave the row unclaimed while the serving code uses the version, for example after a rollback,
            // or while a stopped release from before the row can still serve again.
            if (! ($eligible[$row->kind][$row->version] ?? false)
                || ! $this->reviews->reviewable((string) $row->kind, (int) $row->version, $policies[$row->kind] ?? null)) {
                continue;
            }
            try {
                // A failing row fails only itself; the other rows, also of its repository, still file.
                if (! $this->reviews->rollbackSafe($row, $layout, $live)) {
                    continue;
                }
                $project = Project::findByRepositoryOrigin((string) $row->repository)
                    ?? throw new RuntimeException("Register a Project for {$row->repository} to file Operation cleanup Tasks.");
                // Tasks publish only through the GitHub App. Filing resumes once the Project uses it.
                if ($project->source_access === ProjectSourceAccess::GhCli) {
                    continue;
                }
                $filed += $this->file($row, $project);
            } catch (Throwable $exception) {
                $failures[$row->id] = $exception;
            }
        }

        if ($failures !== []) {
            throw reset($failures);
        }

        return $filed;
    }

    private function file(stdClass $row, Project $project): int
    {
        return DB::transaction(function () use ($row, $project): int {
            // Claim first. A failed Task creation rolls back both the claim and its Task.
            if (DB::table('operation_cleanup_reviews')->where('id', $row->id)->whereNull('processed_at')->update(['processed_at' => now()]) === 0) {
                return 0;
            }
            $key = ['project_id' => $project->id, 'kind' => $row->kind, 'version' => $row->version];
            $receipt = DB::table('operation_cleanup_tasks')->where($key);
            if ($row->brief === null) {
                $receipt->whereNull('removed_sha')->update(['removed_sha' => $row->sha]);

                return 0;
            }
            if ($receipt->exists()) {
                return 0;
            }
            $task = $this->tasks->execute(new CreateTaskGroupData($project->id, "Retire {$row->kind} v{$row->version}", (string) $row->brief, TaskGroupStatus::Backlog, []));
            DB::table('operation_cleanup_tasks')->insert([...$key, 'task_id' => $task->id, 'filed_sha' => $row->sha]);

            return 1;
        }, 3);
    }
}
