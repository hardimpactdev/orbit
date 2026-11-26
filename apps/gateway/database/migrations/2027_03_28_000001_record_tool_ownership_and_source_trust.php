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
            $table->json('origins')->nullable();
        });

        // How each Tool became installed, whether Orbit acquired it, and the status its latest receipt left.
        $tools = [];
        // Each Tool's latest direct install or update request: its Operation and the source it named, or none.
        $latest = [];
        // Each source's latest event, its Operation, and every definition its history named, by Node and name.
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
                $tools[$tool] ??= ['owned' => false, 'installed' => null, 'last' => null];
                // Adoption leaves no Operation: a Tool admitted as installed with no earlier acquisition or observation was adopted.
                if (($input['tool_status'] ?? null) === 'installed' && $tools[$tool]['installed'] === null) {
                    $tools[$tool] = ['owned' => true, 'installed' => 'adopted', 'last' => 'installed'];
                }
                if ($operation->kind !== 'tool.remove' && in_array($operation->status, ['succeeded', 'failed'], true)) {
                    $acquired = $succeeded && ($result['outcome'] ?? null) === 'applied';
                    $tools[$tool] = ['owned' => $tools[$tool]['owned'] || $acquired,
                        'installed' => $acquired ? 'acquired' : ($succeeded ? ($tools[$tool]['installed'] ?? 'observed') : $tools[$tool]['installed']),
                        'last' => $succeeded ? 'installed' : 'failed'];
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

        foreach (DB::table('tools')->get(['id', 'node_id', 'status', 'apt_repository_id', 'desired_repository']) as $tool) {
            $history = $tools[$tool->id] ?? null;
            // Installed now although its latest receipt left it failed: only an adoption, which the preceding release did not record,
            // turns a failed Tool installed without an Operation.
            $adopted = $history !== null && $tool->status === 'installed' && $history['last'] !== 'installed';
            $changes = ['owned' => $history === null ? $tool->status === 'installed' : ($history['owned'] || $adopted)];
            // A failed install that wrote its source kept no link to it: a still pending request names its desired source, unless the
            // source's removal was confirmed after it. An installed Tool without a link has no source.
            $request = $latest[$tool->id] ?? null;
            $named = $request['repository']['name'] ?? null;
            $withdrawn = $request !== null && is_string($named) && ($sources[$tool->node_id][$named]['event'] ?? null) === 'removed'
                && $sources[$tool->node_id][$named]['operation'] > $request['operation'];
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
            // Every origin the history may have published under this name stays restricted until a write proves the source again.
            $hosts = [];
            foreach ([$definition, ...($written ? [] : (array) ($last['definitions'] ?? []))] as $named) {
                foreach (is_array($named) ? (array) ($named['uris'] ?? []) : [] as $uri) {
                    $hosts[] = is_string($uri) ? strtolower((string) parse_url($uri, PHP_URL_HOST)) : '';
                }
            }
            $hosts = array_filter($hosts);
            $hosts = array_values(array_unique($hosts));
            sort($hosts, SORT_STRING);
            DB::table('apt_repositories')->where('id', $record->id)->update(['verified' => $written, 'origins' => json_encode($hosts, JSON_THROW_ON_ERROR)]);
        }
    }

    /**
     * What one direct Operation did to a source, by its own receipt: `written` and `removed` only on success, nothing when it skipped
     * the source or failed at a recorded step before the source, `uncertain` otherwise, including an interruption with no failed step.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    private function sourceEvent(string $kind, array $input, array $result, bool $succeeded, mixed $failedStep): ?string
    {
        $given = ($input['repository_given'] ?? false) === true;
        $prePublication = ['Prepare package commands', 'Fetch the signing key', 'Read the key fingerprint', 'Assert the key fingerprint',
            'Dearmor the signing key', 'Prepare apt keyrings', 'Set up the Tool Manager'];
        $preRepin = ['Prepare package commands', 'Read manager version', 'Read installed package', 'Plan the removal', 'Choose package action',
            'Remove the package', 'Check apt absence', 'Check it is gone', 'Decode package state'];
        if ($kind === 'tool.remove') {
            if (($input['remove_repository'] ?? false) === true) {
                return $succeeded ? (($result['repository_removed'] ?? null) === true ? 'removed' : 'uncertain')
                    : (in_array($failedStep, $preRepin, true) ? null : 'uncertain');
            }

            // Repinning survivors is the only source write of a non-last removal.
            return $succeeded || in_array($failedStep, $preRepin, true) ? null : 'uncertain';
        }
        if ($succeeded) {
            // A successful write that skipped the key and source neither proves nor doubts them.
            return $given ? 'written' : null;
        }
        if (in_array($failedStep, $prePublication, true)) {
            return null;
        }

        // A failed update that skipped publication rewrote the same preferences its members already had.
        return $given || $kind !== 'tool.update' ? 'uncertain' : null;
    }

    /**
     * A converge whose Tools phase ran wrote each planned source and judged each planned Tool; one that skipped the phase wrote nothing.
     * The preceding release kept no per-Tool write count, so an installed observation is an observation unless the Tool was owned, and
     * a plan captured as installed before any other evidence names an adopted Tool.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     * @param  array<int, array{owned: bool, installed: ?string, last: ?string}>  $tools
     * @param  array<int, array<string, array<string, mixed>>>  $sources
     */
    private function converge(int $id, int $nodeId, array $input, array $result, bool $ran, array &$tools, array &$sources): void
    {
        $phase = collect((array) ($result['phases'] ?? []))->firstWhere('name', 'tools');
        $plans = array_filter((array) data_get($input, '_converge.intent.tools', []), static fn (mixed $plan): bool => is_array($plan) && is_int($plan['id'] ?? null));
        foreach ($plans as $plan) {
            $tools[$plan['id']] ??= ['owned' => false, 'installed' => null, 'last' => null];
            if (($plan['tool_status'] ?? null) === 'installed' && $tools[$plan['id']]['installed'] === null) {
                $tools[$plan['id']] = ['owned' => true, 'installed' => 'adopted', 'last' => 'installed'];
            }
        }
        if (! $ran || ! is_array($phase) || in_array($phase['status'] ?? null, ['unchanged', 'skipped'], true)) {
            return;
        }
        $installed = [];
        foreach ((array) ($result['tools'] ?? []) as $observation) {
            if (is_array($observation) && is_int($observation['id'] ?? null) && in_array($observation['status'] ?? null, ['installed', 'failed'], true)) {
                $tools[$observation['id']] ??= ['owned' => false, 'installed' => null, 'last' => null];
                $tools[$observation['id']]['last'] = $observation['status'];
                if ($observation['status'] === 'installed') {
                    $installed[$observation['id']] = true;
                    $tools[$observation['id']]['installed'] ??= 'observed';
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
     * Every definition a source's history named, and its latest event with that event's Operation and definition.
     *
     * @param  array<int, array<string, array<string, mixed>>>  $sources
     * @param  array<mixed>  $repository
     */
    private function note(array &$sources, int $nodeId, string $name, array $repository, ?string $event, int $operation): void
    {
        $entry = $sources[$nodeId][$name] ?? [];
        $definitions = is_array($entry['definitions'] ?? null) ? $entry['definitions'] : [];
        $definitions[] = $repository;
        $sources[$nodeId][$name] = [...$entry, 'definitions' => $definitions,
            ...($event === null ? [] : ['event' => $event, 'operation' => $operation, 'definition' => $repository])];
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
            $table->dropColumn(['verified', 'origins']);
        });
        Schema::table('tools', static function (Blueprint $table): void {
            $table->dropColumn('owned');
        });
    }
};
