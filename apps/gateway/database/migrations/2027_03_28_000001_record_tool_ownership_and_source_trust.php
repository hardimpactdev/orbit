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
            // The newest Operation whose receipt changed the row's state, its ownership and its installed version (Tool::receive).
            $table->json('receipts')->nullable();
        });
        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->boolean('verified')->default(true);
        });
        Schema::table('tool_managers', static function (Blueprint $table): void {
            // The newest Operation whose receipt changed the manager's state (ToolManagerRecord::receive).
            $table->unsignedBigInteger('receipt')->nullable();
        });

        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->json('entries')->nullable();
            // Each open Operation that may write the source, and its newest settled end (AptSourceTrust::settle).
            $table->json('writers')->nullable();
            $table->json('settled')->nullable();
        });

        // Per Tool: whether Orbit acquired it, how it became installed, the status its latest receipt left, and the installed version
        // that the history itself explains.
        $tools = [];
        // Each Tool's latest direct install or update request: its Operation and the source it named, or none.
        $latest = [];
        // Per source name: its latest write event and that event's definition, the definitions it may have on disk since its last
        // confirmed write or removal, and the Operations that confirmed its removal.
        $sources = [];
        // The newest Operation that changed each Tool Manager's state, by Node and name, and each Tool's removal the preceding release
        // expired with its run unsettled, by Tool.
        $managers = [];
        $suspended = [];
        $operations = DB::table('operations')->whereIn('kind', ['tool.install', 'tool.update', 'tool.remove', 'node.converge'])
            ->orderBy('id')->get(['id', 'kind', 'node_id', 'status', 'error_code', 'attempts', 'state', 'input', 'result']);
        foreach ($operations as $operation) {
            $input = $this->json($operation->input);
            $result = $this->json($operation->result);
            $state = $this->json($operation->state);
            // A run that never reached the Node wrote nothing: no attempt and no bound native run.
            $ran = $operation->attempts > 0 || isset($state['swamp_run_id']);
            $succeeded = $operation->status === 'succeeded';
            // Work still open at the upgrade, queued, running, or expired by the preceding release with its run unsettled, is settled by
            // the upgraded Gateway when its end is read: its sources keep it as an open writer, and it changes no Tool here.
            $terminal = in_array(data_get($state, 'native_timeout.native_terminal_status'), ['succeeded', 'failed', 'cancelled', 'interrupted'], true)
                || data_get($state, 'native_timeout.no_run_verified_at') !== null;
            $expired = $operation->error_code === 'operation.timed_out' && ! $terminal;
            $open = ! in_array($operation->status, ['succeeded', 'failed', 'cancelled', 'interrupted'], true) || $expired;
            if ($operation->kind === 'node.converge') {
                if ($open) {
                    foreach ((array) data_get($input, '_converge.intent.tools', []) as $plan) {
                        if (is_array($plan) && is_array($plan['repository'] ?? null) && is_string($plan['repository']['name'] ?? null)) {
                            $this->writer($sources, $operation->node_id, $plan['repository']['name'], $operation->id, $plan['repository']);
                        }
                    }

                    continue;
                }
                $this->converge($operation->id, $operation->node_id, $input, $result, $ran, $tools, $sources);

                continue;
            }
            $repository = is_array($input['repository'] ?? null) ? $input['repository'] : null;
            if ($open) {
                // A request still pending names the Tool's desired source, as a failed one does.
                if (is_int($input['tool_id'] ?? null) && $operation->kind !== 'tool.remove') {
                    $latest[$input['tool_id']] = ['operation' => $operation->id, 'repository' => $repository];
                }
                if ($repository !== null && is_string($repository['name'] ?? null)) {
                    $this->writer($sources, $operation->node_id, $repository['name'], $operation->id, $repository);
                }
                // The preceding release did not set aside the authority of a removal it expired; the upgrade does, as the Gateway now
                // does at expiry, until the run's end is read or Swamp verifies it never ran.
                if ($expired && $operation->kind === 'tool.remove' && $ran && is_int($input['tool_id'] ?? null)) {
                    $suspended[$input['tool_id']] = ['operation' => $operation->id, 'code' => $operation->error_code];
                }

                continue;
            }
            $tool = is_int($input['tool_id'] ?? null) ? $input['tool_id'] : null;
            if ($tool !== null) {
                // Admission captured the Tool's status and installed version (StartToolOperationAction).
                $this->capture($tools, $tool, is_string($input['tool_status'] ?? null) ? $input['tool_status'] : null,
                    is_string($input['installed_version'] ?? null) ? $input['installed_version'] : null, $operation->id);
                // Every projected end but a cancel set the Tool's status and installed version (ToolIntentProjection::projectState). Only
                // the terminal of a bound native run is projected: an expiry, a refused or lost start, or another Gateway-side failure
                // leaves the row as it was.
                $projected = $succeeded || (isset($state['swamp_run_id']) && ($operation->error_code === null
                    || str_starts_with((string) $operation->error_code, 'tool.') || $operation->error_code === 'operation.native_failed'));
                if ($projected) {
                    $tools[$tool]['stamp'] = $operation->id * 2;
                    if (is_string($input['manager'] ?? null)) {
                        $managers[$operation->node_id][$input['manager']] = $operation->id;
                    }
                }
                if ($operation->kind !== 'tool.remove' && $projected && in_array($operation->status, ['succeeded', 'failed', 'interrupted'], true)) {
                    $acquired = $succeeded && ($result['outcome'] ?? null) === 'applied';
                    $version = is_string($result['installed_version'] ?? null) ? $result['installed_version'] : null;
                    $tools[$tool] = [...$tools[$tool], 'owned' => $tools[$tool]['owned'] || $acquired,
                        'installed' => $acquired ? 'acquired' : ($succeeded ? ($tools[$tool]['installed'] ?? 'observed') : $tools[$tool]['installed']),
                        'last' => $succeeded ? 'installed' : 'failed', 'version' => $succeeded ? $version : ($version ?? $tools[$tool]['version'])];
                }
                // A removal that may have reached its package, a nonzero exit included, ended the claim; only one that failed before its
                // decision, or that its decision refused, left the package untouched.
                if ($operation->kind === 'tool.remove' && $projected && in_array($operation->status, ['failed', 'interrupted'], true)
                    && ! $this->untouchedPackage(data_get($state, 'native_failure.failed_step'), $operation->error_code)) {
                    $tools[$tool]['owned'] = false;
                }
                if ($operation->kind !== 'tool.remove') {
                    $latest[$tool] = ['operation' => $operation->id, 'repository' => is_array($input['repository'] ?? null) ? $input['repository'] : null];
                }
            }
            $name = $repository['name'] ?? null;
            if ($repository === null || ! is_string($name) || ! $ran) {
                continue;
            }
            $event = $this->sourceEvent($operation->kind, $input, $result, $succeeded, data_get($state, 'native_failure.failed_step'));
            $this->note($sources, $operation->node_id, $name, $repository, $event, $operation->id);
        }

        $latestOperation = ValidatedData::integer(DB::table('operations')->max('id') ?? 0);
        foreach (DB::table('tools')->get(['id', 'node_id', 'tool_manager_id', 'status', 'installed_version', 'failed_operation', 'apt_repository_id', 'desired_repository', 'error_code']) as $tool) {
            $history = $tools[$tool->id] ?? null;
            // Only an adoption, which the preceding release recorded without an Operation, makes a Tool installed that its latest receipt
            // left failed, or gives it an installed version other than the one its history last recorded.
            $adopted = $history !== null && $tool->status === 'installed'
                && ($history['last'] === 'failed' || ($tool->installed_version !== null && $tool->installed_version !== $history['version']));
            $changes = ['owned' => $history === null ? $tool->status === 'installed' : ($history['owned'] || $adopted)];
            // Each field keeps the order of the preceding release's newest receipt for it; an adoption after them orders after the latest
            // Operation (Tool::receive, Tool::adopt). A late receipt of an older Operation therefore never sets back a newer one.
            $stamp = $adopted ? $latestOperation * 2 + 1 : ($history['stamp'] ?? null);
            $receipts = $stamp === null ? [] : ['state' => $stamp, 'owner' => $stamp, 'version' => $stamp];
            if (isset($suspended[$tool->id])) {
                $receipts['suspended'] = ['operation' => $suspended[$tool->id]['operation'], 'owned' => $changes['owned'], 'status' => $tool->status,
                    'failed_operation' => $tool->failed_operation, 'error_code' => $tool->error_code];
                // The Tool waits for `tool:remove` with the expired removal's own code, as ToolIntentProjection::timedOut leaves it.
                $changes = [...$changes, 'owned' => false, 'status' => 'failed', 'failed_operation' => 'remove', 'error_code' => $suspended[$tool->id]['code']];
            }
            $changes['receipts'] = $receipts === [] ? null : json_encode($receipts, JSON_THROW_ON_ERROR);
            $manager = DB::table('tool_managers')->where('id', $tool->tool_manager_id)->first(['node_id', 'name']);
            $convergeStamp = $history['converged'] ?? null;
            if ($manager !== null && is_int($convergeStamp)) {
                $managers[$manager->node_id][$manager->name] = max($managers[$manager->node_id][$manager->name] ?? 0, $convergeStamp);
            }
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
        foreach ($managers as $nodeId => $byName) {
            foreach ($byName as $name => $operation) {
                DB::table('tool_managers')->where('node_id', $nodeId)->where('name', $name)->update(['receipt' => $operation]);
            }
        }

        foreach ($sources as $nodeId => $byName) {
            foreach ($byName as $name => $last) {
                $record = DB::table('apt_repositories')->where('node_id', $nodeId)->where('name', $name)->first(['id']);
                if ($record === null && ((isset($last['event']) && $last['event'] !== 'removed') || ($last['writers'] ?? []) !== [])) {
                    // A source that may still be on disk keeps its own record, whatever package names collide.
                    DB::table('apt_repositories')->insert(['node_id' => $nodeId, 'name' => $name, 'verified' => false, 'created_at' => now(),
                        'updated_at' => now(), 'definition' => json_encode($last['definition'] ?? array_values(is_array($last['writers'] ?? null) ? $last['writers'] : [])[0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
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
            // Work still open at the upgrade is an open writer: the source proves nothing until its end, read after the upgrade, settles it.
            $writers = [];
            foreach ((array) ($last['writers'] ?? []) as $operation => $wrote) {
                $wrote = ValidatedData::object($wrote);
                $writers[$operation] = [];
                foreach ((array) ($wrote['uris'] ?? []) as $uri) {
                    foreach ((array) ($wrote['suites'] ?? []) as $suite) {
                        $writers[$operation][] = is_string($uri) && is_string($suite) ? rtrim($uri, '/').' '.$suite : '';
                    }
                }
            }
            $possible = array_values(array_unique([...$entries, ...array_filter(array_merge([], ...array_values($writers)))]));
            sort($possible, SORT_STRING);
            // As AptSourceTrust::recompute: trust holds while no writer newer than the settled end is open.
            $settledAt = is_int($last['operation'] ?? null) ? $last['operation'] : 0;
            $newer = array_filter(array_keys($writers), static fn (int|string $writer): bool => (int) $writer > $settledAt) !== [];
            DB::table('apt_repositories')->where('id', $record->id)->update(['verified' => $written && ! $newer,
                'entries' => json_encode($possible, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'writers' => json_encode((object) $writers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'settled' => json_encode(['verified' => $written, 'definition' => $definition, 'entries' => $entries, 'operation' => is_int($last['operation'] ?? null) ? $last['operation'] : 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
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
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<int, array<string, array<string, mixed>>>  $sources
     */
    private function converge(int $id, int $nodeId, array $input, array $result, bool $ran, array &$tools, array &$sources): void
    {
        $phase = collect((array) ($result['phases'] ?? []))->firstWhere('name', 'tools');
        $plans = array_filter((array) data_get($input, '_converge.intent.tools', []), static fn (mixed $plan): bool => is_array($plan) && is_int($plan['id'] ?? null));
        foreach ($plans as $plan) {
            $this->capture($tools, ValidatedData::integer($plan['id']), null, is_string($plan['installed_version'] ?? null) ? $plan['installed_version'] : null, $id);
        }
        if (! $ran || ! is_array($phase) || in_array($phase['status'] ?? null, ['unchanged', 'skipped'], true)) {
            return;
        }
        $installed = [];
        foreach ((array) ($result['tools'] ?? []) as $observation) {
            if (is_array($observation) && is_int($observation['id'] ?? null) && in_array($observation['status'] ?? null, ['installed', 'failed'], true)) {
                $toolId = $observation['id'];
                $tools[$toolId] ??= ['owned' => false, 'installed' => null, 'last' => null, 'version' => null];
                $tools[$toolId]['last'] = $observation['status'];
                $tools[$toolId]['stamp'] = $id * 2;
                if ($observation['status'] === 'installed') {
                    $installed[$toolId] = true;
                    $tools[$toolId]['converged'] = $id;
                    $tools[$toolId]['installed'] ??= 'observed';
                    $tools[$toolId]['version'] = is_string($observation['installed_version'] ?? null) ? $observation['installed_version'] : $tools[$toolId]['version'];
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
     * The adoption orders just before the capturing Operation.
     *
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function capture(array &$tools, int $id, ?string $status, ?string $version, int $operation): void
    {
        $history = $tools[$id] ?? ['owned' => false, 'installed' => null, 'last' => null, 'version' => null];
        if (($status === 'installed' && $history['last'] === 'failed') || ($version !== null && $version !== $history['version'])) {
            $history = [...$history, 'owned' => true, 'installed' => 'adopted', 'last' => 'installed', 'version' => $version, 'stamp' => $operation * 2 - 1];
        }
        $tools[$id] = $history;
    }

    /**
     * Work still open at the upgrade that may write a source.
     *
     * @param  array<int, array<string, array<string, mixed>>>  $sources
     * @param  array<mixed>  $repository
     */
    private function writer(array &$sources, int $nodeId, string $name, int $operation, array $repository): void
    {
        $entry = $sources[$nodeId][$name] ?? [];
        $writers = is_array($entry['writers'] ?? null) ? $entry['writers'] : [];
        $writers[$operation] = $repository;
        $sources[$nodeId][$name] = [...$entry, 'writers' => $writers];
    }

    /**
     * A preceding-release removal that failed left its package untouched only when it failed before its decision, or its decision
     * refused (`tool.removal_plan_unsafe`, recorded at the end check). Any other end may have reached the package.
     */
    private function untouchedPackage(mixed $failedStep, ?string $errorCode): bool
    {
        return in_array($failedStep, ['Prepare package commands', 'Read manager version', 'Read installed package', 'Read macOS version', 'Plan the removal', 'Choose package action'], true)
            || ($failedStep === 'Assert package state' && $errorCode === 'tool.removal_plan_unsafe');
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
            $table->dropColumn(['verified', 'entries', 'writers', 'settled']);
        });
        Schema::table('tools', static function (Blueprint $table): void {
            $table->dropColumn(['owned', 'receipts']);
        });
        Schema::table('tool_managers', static function (Blueprint $table): void {
            $table->dropColumn('receipt');
        });
    }
};
