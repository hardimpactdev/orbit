<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the task lifecycle times and the thread cost that task insights read
 * ([Lifecycle times](/reference/tasks#lifecycle-times)). Ended tasks are filled only from records
 * that hold the exact time; a time without one stays null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('merged_at')->nullable();
        });
        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->decimal('cost_usd', 12, 6)->nullable();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('agent_threads', static function (Blueprint $table): void {
            $table->dropColumn('cost_usd');
        });
        Schema::table('tasks', static function (Blueprint $table): void {
            $table->dropColumn(['ready_at', 'ended_at', 'merged_at']);
        });
    }

    private function backfill(): void
    {
        $ready = [];
        $ended = [];
        $created = [];
        $commands = DB::table('activity_log')->where('log_name', 'commands')->where('status', 'succeeded')
            ->whereIn('command', ['tasks:create', 'tasks:update', 'tasks:complete', 'tasks:cancel'])
            ->orderBy('id')->get(['command', 'properties', 'created_at']);
        foreach ($commands as $command) {
            $properties = json_decode((string) $command->properties, true);
            $input = is_array($properties) && is_array($properties['input'] ?? null) ? $properties['input'] : [];
            $path = is_array($properties) && is_string($properties['path'] ?? null) ? $properties['path'] : '';
            $id = preg_match('#^api/v1/task-groups/(\d+)(?:/(?:complete|cancel))?$#', $path, $match) === 1 ? (int) $match[1] : null;
            if ($command->command === 'tasks:create' && ($input['status'] ?? null) === 'todo' && is_string($input['title'] ?? null)) {
                $created[] = ['title' => $input['title'], 'project_id' => is_numeric($input['project_id'] ?? null) ? (int) $input['project_id'] : null, 'at' => $command->created_at];
            } elseif ($command->command === 'tasks:update' && $id !== null && ($input['status'] ?? null) === 'todo') {
                $ready[$id] ??= $command->created_at;
            } elseif (in_array($command->command, ['tasks:complete', 'tasks:cancel'], true) && $id !== null) {
                $ended[$id] ??= $command->created_at;
            }
        }

        $merged = [];
        foreach (DB::table('jev_decisions')->whereNotNull('task_group_id')->whereNotNull('merged_at')->orderBy('id')->get(['task_group_id', 'merged_at']) as $decision) {
            // Jev stores GitHub's ISO time; the column holds the database format.
            $merged[(int) $decision->task_group_id] ??= CarbonImmutable::parse($decision->merged_at)->utc()->format('Y-m-d H:i:s');
        }
        foreach (DB::table('activity_log')->where('log_name', 'tasks')->where('description', 'pull request merged')->orderBy('id')->get(['subject_id', 'created_at']) as $entry) {
            $merged[(int) $entry->subject_id] ??= $entry->created_at;
        }

        foreach (DB::table('tasks')->whereNull('parent_id')->orderBy('id')->get(['id', 'title', 'project_id', 'status', 'created_at']) as $group) {
            $createdReady = null;
            foreach ($created as $entry) {
                // The create entry has no task id; its title, Project, and time name the task it made.
                if ($entry['title'] === $group->title && $entry['project_id'] === $group->project_id
                    && abs(CarbonImmutable::parse($entry['at'])->diffInSeconds(CarbonImmutable::parse($group->created_at))) <= 5) {
                    $createdReady = $group->created_at;
                    break;
                }
            }
            $endedStatus = in_array($group->status, ['completed', 'failed', 'cancelled'], true);
            $mergedAt = $merged[$group->id] ?? null;
            $values = array_filter([
                'ready_at' => $createdReady ?? $ready[$group->id] ?? null,
                'merged_at' => $mergedAt,
                'ended_at' => $endedStatus ? ($ended[$group->id] ?? $mergedAt) : null,
            ], static fn (mixed $value): bool => $value !== null);
            if ($values !== []) {
                DB::table('tasks')->where('id', $group->id)->update($values);
            }
        }
    }
};
