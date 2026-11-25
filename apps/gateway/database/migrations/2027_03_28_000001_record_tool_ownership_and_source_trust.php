<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records uninstall authority, apt source trust and source cleanup handles from the preceding release's history, replayed in Operation
 * order (docs/reference/tools.md#upgrade-from-the-preceding-release). Only affirmative evidence grants ownership or trust; a source
 * Orbit may still have on disk keeps a cleanup handle that is never a membership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tools', static function (Blueprint $table): void {
            $table->boolean('owned')->default(false);
        });
        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->boolean('verified')->default(true);
        });

        $acquired = [];
        $published = [];
        $sources = [];
        $operations = DB::table('operations')->whereIn('kind', ['tool.install', 'tool.update', 'tool.remove', 'node.converge'])
            ->orderBy('id')->get(['id', 'kind', 'node_id', 'status', 'attempts', 'state', 'input', 'result']);
        foreach ($operations as $operation) {
            $input = $this->json($operation->input);
            $result = $this->json($operation->result);
            $state = $this->json($operation->state);
            // A run that never reached the Node wrote nothing: no attempt and no bound native run.
            $ran = $operation->attempts > 0 || isset($state['swamp_run_id']);
            $succeeded = $operation->status === 'succeeded';
            if ($operation->kind === 'node.converge') {
                $installed = [];
                foreach ((array) ($result['tools'] ?? []) as $observation) {
                    if (is_array($observation) && ($observation['status'] ?? null) === 'installed' && is_int($observation['id'] ?? null)) {
                        $installed[$observation['id']] = true;
                        $acquired[$observation['id']] = true;
                    }
                }
                foreach ((array) data_get($input, '_converge.intent.tools', []) as $plan) {
                    $repository = is_array($plan) && is_array($plan['repository'] ?? null) ? $plan['repository'] : null;
                    $name = $repository['name'] ?? null;
                    if ($repository !== null && is_string($name) && $ran) {
                        $confirmed = $succeeded && is_int($plan['id'] ?? null) && isset($installed[$plan['id']]);
                        $sources[$operation->node_id][$name] = ['event' => $confirmed ? 'written' : 'uncertain',
                            'definition' => $repository, 'package' => $plan['package'] ?? null];
                    }
                }

                continue;
            }
            $tool = $input['tool_id'] ?? null;
            $repository = is_array($input['repository'] ?? null) ? $input['repository'] : null;
            $name = $repository['name'] ?? null;
            if ($succeeded && $operation->kind !== 'tool.remove' && is_int($tool)) {
                $acquired[$tool] = true;
            }
            if ($repository === null || ! is_string($name) || ! $ran) {
                continue;
            }
            $event = match (true) {
                $operation->kind === 'tool.remove' => ($input['remove_repository'] ?? false) !== true ? null
                    : ($succeeded && ($result['repository_removed'] ?? null) === true ? 'removed' : 'uncertain'),
                $succeeded => 'written',
                default => 'uncertain',
            };
            if ($event !== null) {
                $sources[$operation->node_id][$name] = ['event' => $event, 'definition' => $repository, 'package' => $input['package'] ?? null];
            }
            if ($event === 'uncertain' && $operation->kind !== 'tool.remove' && is_int($tool)) {
                $published[$tool] = $repository;
            }
        }

        foreach (DB::table('tools')->get(['id', 'status', 'apt_repository_id', 'desired_repository']) as $tool) {
            $changes = ['owned' => $tool->status === 'installed' || isset($acquired[$tool->id])];
            // A failed install that wrote its source before failing kept no link to it in the preceding release.
            if ($tool->apt_repository_id === null && $tool->desired_repository === null && isset($published[$tool->id])) {
                $changes['desired_repository'] = json_encode($published[$tool->id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            DB::table('tools')->where('id', $tool->id)->update($changes);
        }

        foreach (DB::table('apt_repositories')->get(['id', 'node_id', 'name', 'definition']) as $record) {
            $latest = $sources[$record->node_id][$record->name] ?? null;
            $written = ($latest['event'] ?? null) === 'written' && $this->json($record->definition) == $latest['definition'];
            DB::table('apt_repositories')->where('id', $record->id)->update(['verified' => $written]);
        }
        foreach ($sources as $nodeId => $byName) {
            foreach ($byName as $name => $latest) {
                if ($latest['event'] !== 'removed') {
                    $this->keepCleanupHandle((int) $nodeId, (string) $name, $latest);
                }
            }
        }
    }

    /**
     * A source Orbit may still have on disk that no Tool names gets a withdrawn, unowned apt Tool, so `tool:remove` can clean it without
     * reinstalling or removing the package.
     *
     * @param  array{event: string, definition: array<string, mixed>, package: mixed}  $latest
     */
    private function keepCleanupHandle(int $nodeId, string $name, array $latest): void
    {
        $named = DB::table('tools')->where('node_id', $nodeId)->where(static fn ($query) => $query->where('desired_repository->name', $name)
            ->orWhereIn('apt_repository_id', DB::table('apt_repositories')->where('node_id', $nodeId)->where('name', $name)->select('id')))->exists();
        $manager = DB::table('tool_managers')->where('node_id', $nodeId)->where('name', 'apt')->value('id');
        if ($named || $manager === null || ! is_string($latest['package'])
            || DB::table('tools')->where('tool_manager_id', $manager)->where('package', $latest['package'])->exists()) {
            return;
        }
        if (! DB::table('apt_repositories')->where('node_id', $nodeId)->where('name', $name)->exists()) {
            DB::table('apt_repositories')->insert(['node_id' => $nodeId, 'name' => $name, 'verified' => false, 'created_at' => now(), 'updated_at' => now(),
                'definition' => json_encode($latest['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
        DB::table('tools')->insert(['node_id' => $nodeId, 'tool_manager_id' => $manager, 'package' => $latest['package'], 'owned' => false,
            'desired_repository' => json_encode($latest['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'status' => 'failed', 'failed_operation' => 'remove', 'error_code' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function down(): void
    {
        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->dropColumn('verified');
        });
        Schema::table('tools', static function (Blueprint $table): void {
            $table->dropColumn('owned');
        });
    }
};
