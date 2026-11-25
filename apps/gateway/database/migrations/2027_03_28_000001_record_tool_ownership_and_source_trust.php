<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records uninstall authority, apt source trust and source cleanup from the preceding release's receipts, replayed in Operation order
 * (docs/reference/tools.md#upgrade-from-the-preceding-release). Only an acquisition grants ownership and only a write that ran grants
 * trust; an observation, a skipped write or a membership the user never asked for is no evidence. Each source that may still be on disk
 * keeps its own record, which a full convergence removes once no Tool names it.
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

        // How each Tool became installed, and whether Orbit acquired it.
        $tools = [];
        // The source of each Tool's latest direct install or update; empty when that request named none.
        $latest = [];
        // Each source's latest event by Node and name.
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
                $this->converge($operation->node_id, $input, $result, $ran, $tools, $sources);

                continue;
            }
            $tool = is_int($input['tool_id'] ?? null) ? $input['tool_id'] : null;
            if ($tool !== null) {
                $tools[$tool] ??= ['owned' => false, 'installed' => null];
                // Adoption leaves no Operation: a Tool admitted as installed with no earlier acquisition or observation was adopted.
                if (($input['tool_status'] ?? null) === 'installed' && $tools[$tool]['installed'] === null) {
                    $tools[$tool] = ['owned' => true, 'installed' => 'adopted'];
                }
                if ($succeeded && $operation->kind !== 'tool.remove') {
                    $acquired = ($result['outcome'] ?? null) === 'applied';
                    $tools[$tool] = ['owned' => $tools[$tool]['owned'] || $acquired,
                        'installed' => $acquired ? 'acquired' : ($tools[$tool]['installed'] ?? 'observed')];
                }
                if ($operation->kind !== 'tool.remove') {
                    $latest[$tool] = is_array($input['repository'] ?? null) ? $input['repository'] : [];
                }
            }
            $repository = is_array($input['repository'] ?? null) ? $input['repository'] : null;
            $name = $repository['name'] ?? null;
            if ($repository === null || ! is_string($name) || ! $ran) {
                continue;
            }
            $given = ($input['repository_given'] ?? false) === true;
            $event = match ($operation->kind) {
                // Without remove_repository the removal only repinned survivors: a success left the source as it was.
                'tool.remove' => ($input['remove_repository'] ?? false) === true
                    ? ($succeeded && ($result['repository_removed'] ?? null) === true ? 'removed' : 'uncertain')
                    : ($succeeded ? null : 'uncertain'),
                // A successful write with repository_given false skipped the key and source: it neither proves nor doubts them.
                default => $given ? ($succeeded ? 'written' : 'uncertain') : ($succeeded ? null : 'uncertain'),
            };
            if ($event !== null) {
                $sources[$operation->node_id][$name] = ['event' => $event, 'definition' => $repository];
            }
        }

        foreach (DB::table('tools')->get(['id', 'status', 'apt_repository_id', 'desired_repository']) as $tool) {
            $history = $tools[$tool->id] ?? null;
            $changes = ['owned' => $history === null ? $tool->status === 'installed' : $history['owned']];
            // A failed install that wrote its source kept no link to it: the Tool's own latest request names its desired source.
            if ($tool->apt_repository_id === null && $tool->desired_repository === null && ($latest[$tool->id] ?? []) !== []) {
                $changes['desired_repository'] = json_encode($latest[$tool->id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            DB::table('tools')->where('id', $tool->id)->update($changes);
        }

        foreach ($sources as $nodeId => $byName) {
            foreach ($byName as $name => $last) {
                $record = DB::table('apt_repositories')->where('node_id', $nodeId)->where('name', $name)->first(['id']);
                if ($record === null && $last['event'] !== 'removed') {
                    // A source that may still be on disk keeps its own record, whatever package names collide.
                    DB::table('apt_repositories')->insert(['node_id' => $nodeId, 'name' => $name, 'verified' => false, 'created_at' => now(),
                        'updated_at' => now(), 'definition' => json_encode($last['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
                }
            }
        }
        foreach (DB::table('apt_repositories')->get(['id', 'node_id', 'name', 'definition']) as $record) {
            $last = $sources[$record->node_id][$record->name] ?? null;
            $written = ($last['event'] ?? null) === 'written' && $this->json($record->definition) == $last['definition'];
            DB::table('apt_repositories')->where('id', $record->id)->update(['verified' => $written]);
        }
    }

    /**
     * A converge whose Tools phase ran wrote each planned source and judged each planned Tool; one that skipped the phase wrote nothing.
     * The preceding release kept no per-Tool write count, so an installed observation is an observation unless the Tool was owned.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     * @param  array<int, array{owned: bool, installed: ?string}>  $tools
     * @param  array<int, array<string, array{event: string, definition: array<mixed>}>>  $sources
     */
    private function converge(int $nodeId, array $input, array $result, bool $ran, array &$tools, array &$sources): void
    {
        $phase = collect((array) ($result['phases'] ?? []))->firstWhere('name', 'tools');
        if (! $ran || ! is_array($phase) || in_array($phase['status'] ?? null, ['unchanged', 'skipped'], true)) {
            return;
        }
        $installed = [];
        foreach ((array) ($result['tools'] ?? []) as $observation) {
            if (is_array($observation) && ($observation['status'] ?? null) === 'installed' && is_int($observation['id'] ?? null)) {
                $installed[$observation['id']] = true;
                $tools[$observation['id']] ??= ['owned' => false, 'installed' => null];
                $tools[$observation['id']]['installed'] ??= 'observed';
            }
        }
        foreach ((array) data_get($input, '_converge.intent.tools', []) as $plan) {
            $repository = is_array($plan) && is_array($plan['repository'] ?? null) ? $plan['repository'] : null;
            $name = $repository['name'] ?? null;
            if ($repository !== null && is_string($name)) {
                $sources[$nodeId][$name] = ['event' => is_int($plan['id'] ?? null) && isset($installed[$plan['id']]) ? 'written' : 'uncertain',
                    'definition' => $repository];
            }
        }
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
