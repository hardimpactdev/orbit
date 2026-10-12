<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `worker_shared` marks a checkout that the task worker could write. Filter-capable Gateway Git on it then always
 * runs as the worker. Before this column, every checkout on a Node with the worker account was shared. The task
 * worker exists where `pi-server` runs, so existing Instances on such a Node, task workspaces, and Instances
 * transferred from such a Node are marked.
 *
 * Each app-dev Node that has no `pi-server` Process, no marked Instance, and is no task VM gets the explicit
 * opt-out `settings.tasks.worker = none`. On the live fleet that is `services`, which has no worker account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->boolean('worker_shared')->default(false);
        });

        $piNodes = DB::table('processes')
            ->where('name', 'pi-server')
            ->whereIn('owner_type', ['App\\Models\\Node', 'node'])
            ->pluck('owner_id');
        $transferred = DB::table('instance_transfers')->whereIn('source_node_id', $piNodes)->pluck('instance_id');
        $taskWorkspaces = DB::table('tasks')->where('taskable_type', 'instance')->whereNotNull('taskable_id')->pluck('taskable_id');

        DB::table('instances')
            ->where(static fn ($query) => $query
                ->whereIn('node_id', $piNodes)
                ->orWhereIn('id', $transferred)
                ->orWhereIn('id', $taskWorkspaces))
            ->update(['worker_shared' => true]);

        $optOut = DB::table('node_roles')
            ->where('role', 'app-dev')
            ->whereNotIn('node_id', $piNodes)
            ->whereNotIn('node_id', DB::table('instances')->where('worker_shared', true)->select('node_id'))
            ->whereNotIn('node_id', DB::table('task_vms')->whereNotNull('node_id')->select('node_id'))
            ->pluck('node_id');

        foreach (DB::table('nodes')->whereIn('id', $optOut)->get(['id', 'settings']) as $node) {
            $settings = is_string($node->settings) ? json_decode($node->settings, true) : null;
            $settings = is_array($settings) ? $settings : [];
            $settings['tasks'] = ['worker' => 'none'];
            DB::table('nodes')->where('id', $node->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('nodes')->whereNotNull('settings')->get(['id', 'settings']) as $node) {
            $settings = json_decode((string) $node->settings, true);
            if (! is_array($settings) || ! array_key_exists('tasks', $settings)) {
                continue;
            }
            unset($settings['tasks']);
            DB::table('nodes')->where('id', $node->id)->update(['settings' => $settings === [] ? null : json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn('worker_shared');
        });
    }
};
