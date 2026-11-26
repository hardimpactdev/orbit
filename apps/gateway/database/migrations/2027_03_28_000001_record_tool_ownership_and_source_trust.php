<?php

declare(strict_types=1);

use App\Support\ValidatedData;
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

        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->json('entries')->nullable();
        });

        // Per Tool: whether Orbit acquired it, how it became installed, the status its latest receipt left, and the installed version
        // that the history itself explains.
        $tools = [];
        // Each Tool's latest direct install or update request: its Operation and the source it named, or none.
        $latest = [];
        // Per source name: its latest write event and that event's definition, the definitions it may have on disk since its last
        // confirmed write or removal, and the Operations that confirmed its removal.
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
                $this->converge($operation->id, $operation->node_id, $input, $result, $ran, $tools, $sources);

                continue;
            }
            $tool = is_int($input['tool_id'] ?? null) ? $input['tool_id'] : null;
            if ($tool !== null) {
                // Admission captured the Tool's status and installed version (StartToolOperationAction).
                $this->capture($tools, $tool, is_string($input['tool_status'] ?? null) ? $input['tool_status'] : null,
                    is_string($input['installed_version'] ?? null) ? $input['installed_version'] : null);
                // Every projected end but a cancel set the Tool's status and installed version (ToolIntentProjection::projectState).
                if ($operation->kind !== 'tool.remove' && in_array($operation->status, ['succeeded', 'failed', 'interrupted'], true)) {
                    $acquired = $succeeded && ($result['outcome'] ?? null) === 'applied';
                    $version = is_string($result['installed_version'] ?? null) ? $result['installed_version'] : null;
                    $tools[$tool] = ['owned' => $tools[$tool]['owned'] || $acquired,
                        'installed' => $acquired ? 'acquired' : ($succeeded ? ($tools[$tool]['installed'] ?? 'observed') : $tools[$tool]['installed']),
                        'last' => $succeeded ? 'installed' : 'failed', 'version' => $succeeded ? $version : ($version ?? $tools[$tool]['version'])];
                }
                if ($operation->kind !== 'tool.remove') {
                    $latest[$tool] = ['operation' => $operation->id, 'repository' => is_array($input['repository'] ?? null) ? $input['repository'] : null];
                }
            }
            $repository = is_array($input['repository'] ?? null) ? $input['repository'] : null;
            $name = $repository['name'] ?? null;
            if ($repository === null || ! is_string($name) || ! $ran) {
                continue;
            }
            $event = $this->sourceEvent($operation->kind, $input, $result, $succeeded, data_get($state, 'native_failure.failed_step'));
            $this->note($sources, $operation->node_id, $name, $repository, $event, $operation->id);
        }

        foreach (DB::table('tools')->get(['id', 'node_id', 'status', 'installed_version', 'apt_repository_id', 'desired_repository']) as $tool) {
            $history = $tools[$tool->id] ?? null;
            // Only an adoption, which the preceding release recorded without an Operation, makes a Tool installed that its latest receipt
            // left failed, or gives it an installed version other than the one its history last recorded.
            $adopted = $history !== null && $tool->status === 'installed'
                && ($history['last'] === 'failed' || ($tool->installed_version !== null && $tool->installed_version !== $history['version']));
            $changes = ['owned' => $history === null ? $tool->status === 'installed' : ($history['owned'] || $adopted)];
            // A failed install that wrote its source kept no link to it: a still pending request names its desired source, unless a
            // removal of that source name was confirmed after the request, whoever reused the name since. An installed Tool without a
            // link has no source.
            $request = $latest[$tool->id] ?? null;
            $named = $request['repository']['name'] ?? null;
            $withdrawn = $request !== null && is_string($named)
                && max([0, ...ValidatedData::integerList($sources[$tool->node_id][$named]['removals'] ?? [])]) > $request['operation'];
            if ($request !== null && $request['repository'] !== null && $tool->apt_repository_id === null && $tool->desired_repository === null
                && $tool->status !== 'installed' && ! $withdrawn) {
                $changes['desired_repository'] = json_encode($request['repository'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            DB::table('tools')->where('id', $tool->id)->update($changes);
        }

        foreach ($sources as $nodeId => $byName) {
            foreach ($byName as $name => $last) {
                $record = DB::table('apt_repositories')->where('node_id', $nodeId)->where('name', $name)->first(['id']);
                if ($record === null && isset($last['event']) && $last['event'] !== 'removed') {
                    // A source that may still be on disk keeps its own record, whatever package names collide.
                    DB::table('apt_repositories')->insert(['node_id' => $nodeId, 'name' => $name, 'verified' => false, 'created_at' => now(),
                        'updated_at' => now(), 'definition' => json_encode($last['definition'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
                }
            }
        }
        foreach (DB::table('apt_repositories')->get(['id', 'node_id', 'name', 'definition']) as $record) {
            $last = $sources[$record->node_id][$record->name] ?? [];
            $definition = $this->json($record->definition);
            $written = ($last['event'] ?? null) === 'written' && $definition == $last['definition'];
            // Every URI and suite the history may have left under this name stays this source's until a write proves it again.
            $entries = [];
            foreach ([$definition, ...($written ? [] : (array) ($last['possible'] ?? []))] as $named) {
                foreach (is_array($named) ? (array) ($named['uris'] ?? []) : [] as $uri) {
                    foreach (is_array($named) ? (array) ($named['suites'] ?? []) : [] as $suite) {
                        $entries[] = is_string($uri) && is_string($suite) ? rtrim($uri, '/').' '.$suite : null;
                    }
                }
            }
            $entries = array_values(array_unique(array_filter($entries)));
            sort($entries, SORT_STRING);
            DB::table('apt_repositories')->where('id', $record->id)->update(['verified' => $written, 'entries' => json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
    }

    /**
     * What one direct Operation did to a source, by its own receipt and the preceding release's workflows: `written` and `removed` only
     * on success; nothing when it never reached a source write; `uncertain` otherwise, including an interruption with no failed step.
     * - The preceding removal workflow has no repin step: a removal that keeps the source never writes it. A last member's removal
     *   writes only when its decision was ready; a failed step before the decision, or the refusal only a decision makes, never wrote.
     * - The preceding write workflow publishes the key, preferences and source only with `repository_given`, after the key verified.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    private function sourceEvent(string $kind, array $input, array $result, bool $succeeded, mixed $failedStep): ?string
    {
        if ($kind === 'tool.remove') {
            if (($input['remove_repository'] ?? false) !== true) {
                return null;
            }
            if ($succeeded) {
                return ($result['repository_removed'] ?? null) === true ? 'removed' : 'uncertain';
            }
            $beforeDecision = ['Prepare package commands', 'Read manager version', 'Read installed package', 'Read macOS version', 'Plan the removal'];

            return in_array($failedStep, $beforeDecision, true) || ($result['error_code'] ?? null) === 'tool.removal_plan_unsafe' ? null : 'uncertain';
        }
        if (($input['repository_given'] ?? false) !== true) {
            return null;
        }
        if ($succeeded) {
            return 'written';
        }
        $beforePublication = ['Prepare package commands', 'Fetch the signing key', 'Read the key fingerprint', 'Assert the key fingerprint', 'Dearmor the signing key', 'Prepare apt keyrings'];

        return in_array($failedStep, $beforePublication, true) ? null : 'uncertain';
    }

    /**
     * A converge whose Tools phase ran wrote each planned source and judged each planned Tool; one that skipped the phase wrote nothing.
     * Its capture recorded each Tool's installed version (NodeDesiredConvergeInput). The preceding release kept no per-Tool write
     * count, so an installed observation is an observation unless the Tool was owned.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     * @param  array<int, array{owned: bool, installed: ?string, last: ?string, version: ?string}>  $tools
     * @param  array<int, array<string, array<string, mixed>>>  $sources
     */
    private function converge(int $id, int $nodeId, array $input, array $result, bool $ran, array &$tools, array &$sources): void
    {
        $phase = collect((array) ($result['phases'] ?? []))->firstWhere('name', 'tools');
        $plans = array_filter((array) data_get($input, '_converge.intent.tools', []), static fn (mixed $plan): bool => is_array($plan) && is_int($plan['id'] ?? null));
        foreach ($plans as $plan) {
            $this->capture($tools, ValidatedData::integer($plan['id']), null, is_string($plan['installed_version'] ?? null) ? $plan['installed_version'] : null);
        }
        if (! $ran || ! is_array($phase) || in_array($phase['status'] ?? null, ['unchanged', 'skipped'], true)) {
            return;
        }
        $installed = [];
        foreach ((array) ($result['tools'] ?? []) as $observation) {
            if (is_array($observation) && is_int($observation['id'] ?? null) && in_array($observation['status'] ?? null, ['installed', 'failed'], true)) {
                $id = $observation['id'];
                $tools[$id] ??= ['owned' => false, 'installed' => null, 'last' => null, 'version' => null];
                $tools[$id]['last'] = $observation['status'];
                if ($observation['status'] === 'installed') {
                    $installed[$id] = true;
                    $tools[$id]['installed'] ??= 'observed';
                    $tools[$id]['version'] = is_string($observation['installed_version'] ?? null) ? $observation['installed_version'] : $tools[$id]['version'];
                }
            }
        }
        foreach ($plans as $plan) {
            $repository = is_array($plan['repository'] ?? null) ? $plan['repository'] : null;
            $name = $repository['name'] ?? null;
            if ($repository !== null && is_string($name)) {
                $this->note($sources, $nodeId, $name, $repository, isset($installed[ValidatedData::integer($plan['id'])]) ? 'written' : 'uncertain', $id);
            }
        }
    }

    /**
     * A capture of a Tool's status and installed version, at an admission or a converge. Only an adoption, which left no Operation,
     * makes a Tool installed after its latest receipt left it failed, or gives it a version other than the one its history last
     * recorded: every receipt the preceding release projected, an interrupted one included, set that version.
     *
     * @param  array<int, array{owned: bool, installed: ?string, last: ?string, version: ?string}>  $tools
     */
    private function capture(array &$tools, int $id, ?string $status, ?string $version): void
    {
        $history = $tools[$id] ?? ['owned' => false, 'installed' => null, 'last' => null, 'version' => null];
        if (($status === 'installed' && $history['last'] === 'failed') || ($version !== null && $version !== $history['version'])) {
            $history = ['owned' => true, 'installed' => 'adopted', 'last' => 'installed', 'version' => $version];
        }
        $tools[$id] = $history;
    }

    /**
     * A source's latest event and its definition, every definition it may have on disk since its last confirmed write or removal, and
     * the Operations that confirmed its removal.
     *
     * @param  array<int, array<string, array<string, mixed>>>  $sources
     * @param  array<mixed>  $repository
     */
    private function note(array &$sources, int $nodeId, string $name, array $repository, ?string $event, int $operation): void
    {
        if ($event === null) {
            return;
        }
        $entry = $sources[$nodeId][$name] ?? ['possible' => [], 'removals' => []];
        $entry['possible'] = match ($event) {
            'written' => [$repository], 'removed' => [], default => [...(array) $entry['possible'], $repository],
        };
        if ($event === 'removed') {
            $entry['removals'] = [...(array) $entry['removals'], $operation];
        }
        $sources[$nodeId][$name] = [...$entry, 'event' => $event, 'operation' => $operation, 'definition' => $repository];
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
            $table->dropColumn(['verified', 'entries']);
        });
        Schema::table('tools', static function (Blueprint $table): void {
            $table->dropColumn('owned');
        });
    }
};
