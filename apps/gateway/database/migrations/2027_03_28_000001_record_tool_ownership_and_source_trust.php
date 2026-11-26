<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolPackageProtection;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records uninstall authority, apt source trust and source cleanup from the preceding release's receipts, replayed in Operation order
 * (docs/reference/tools.md#upgrade-from-the-preceding-release). Only an acquisition grants ownership and only a write that ran grants
 * trust; an observation, a skipped write or a membership the user never asked for is no evidence. Each source that may still be on disk
 * keeps its own record, which a full convergence removes once no Tool names it. Work still open is admitted again under the current
 * rules: refused when it names a package the current release protects, otherwise given the authority the upgrade recorded.
 */
return new class extends Migration
{
    /** @var array<int, array<string, list<string>>> */
    private array $protection = [];

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

        // Per Tool: whether Orbit acquired it, how it became installed, the status its latest receipt left, the installed version that the
        // history itself explains, and the order of the newest receipt that changed each field.
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
        // Work still open at the upgrade, which the upgraded Gateway dispatches or settles: by Operation, its kind, input and whether its
        // start may have been sent. Queued work the current admission would refuse as protected, by Operation.
        $open = [];
        $refused = [];
        $claims = [];
        $operations = DB::table('operations')->whereIn('kind', ['tool.install', 'tool.update', 'tool.remove', 'node.converge'])
            ->orderBy('id')->get(['id', 'kind', 'node_id', 'status', 'error_code', 'attempts', 'started_at', 'state', 'input', 'result']);
        foreach ($operations as $operation) {
            $input = $this->json($operation->input);
            $result = $this->json($operation->result);
            $state = $this->json($operation->state);
            // A start may have reached the Node once it was sent, also when its reply was lost: an attempt, a bound native run, or a start
            // that set the Operation running. Only Swamp verifying that no run exists says otherwise.
            $ran = ($operation->attempts > 0 || isset($state['swamp_run_id']) || $operation->started_at !== null)
                && data_get($state, 'native_timeout.no_run_verified_at') === null;
            $succeeded = $operation->status === 'succeeded';
            // Work still open at the upgrade, queued, running, or expired by the preceding release with its run unsettled, is settled by
            // the upgraded Gateway when its end is read: its sources keep it as an open writer, and it changes no Tool here.
            $terminal = in_array(data_get($state, 'native_timeout.native_terminal_status'), ['succeeded', 'failed', 'cancelled', 'interrupted'], true)
                || data_get($state, 'native_timeout.no_run_verified_at') !== null;
            $expired = $operation->error_code === 'operation.timed_out' && ! $terminal;
            $isOpen = ! in_array($operation->status, ['succeeded', 'failed', 'cancelled', 'interrupted'], true) || $expired;
            if ($operation->kind === 'node.converge') {
                $plans = (array) data_get($input, '_converge.intent.tools', []);
                if ($isOpen && ! $ran) {
                    // The upgraded Gateway dispatches a queued convergence as its admission now would: without the Tools it protects.
                    $plans = array_values(array_filter($plans, fn (mixed $plan): bool => ! is_array($plan) || ! is_string($plan['manager'] ?? null)
                        || ! in_array($plan['package'] ?? null, $this->protectedPackages($operation->node_id, $plan['manager']), true)));
                    $input = $this->withPlans($input, $plans);
                }
                // Its admission captured each planned Tool's installed version (NodeDesiredConvergeInput).
                foreach ($plans as $plan) {
                    if (is_array($plan) && is_int($plan['id'] ?? null)) {
                        $this->capture($tools, $plan['id'], null, is_string($plan['installed_version'] ?? null) ? $plan['installed_version'] : null, $operation->id);
                    }
                }
                if ($isOpen) {
                    $open[$operation->id] = ['kind' => $operation->kind, 'input' => $input, 'ran' => $ran];
                    foreach ($plans as $plan) {
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
            $tool = is_int($input['tool_id'] ?? null) ? $input['tool_id'] : null;
            if ($isOpen && ! $ran && is_string($input['manager'] ?? null)
                && in_array($input['package'] ?? null, $this->protectedPackages($operation->node_id, $input['manager']), true)) {
                // The upgraded Gateway never dispatches queued work on a package it now protects: it refuses it as its admission would.
                $refused[$operation->id] = $tool;

                continue;
            }
            if ($tool !== null) {
                // Admission captured the Tool's status and installed version (StartToolOperationAction), also for work still open.
                $this->capture($tools, $tool, is_string($input['tool_status'] ?? null) ? $input['tool_status'] : null,
                    is_string($input['installed_version'] ?? null) ? $input['installed_version'] : null, $operation->id);
            }
            if ($isOpen) {
                $open[$operation->id] = ['kind' => $operation->kind, 'input' => $input, 'ran' => $ran];
                // A request still pending names the Tool's desired source, as a failed one does.
                if ($tool !== null && $operation->kind !== 'tool.remove') {
                    $latest[$tool] = ['operation' => $operation->id, 'repository' => $repository];
                }
                if ($repository !== null && is_string($repository['name'] ?? null)) {
                    $this->writer($sources, $operation->node_id, $repository['name'], $operation->id, $repository);
                }
                // The preceding release did not set aside the authority of a removal it expired; the upgrade does, as the Gateway now
                // does at expiry, until the run's end is read or Swamp verifies it never ran.
                if ($expired && $operation->kind === 'tool.remove' && $ran && $tool !== null) {
                    $suspended[$tool] = ['operation' => $operation->id, 'code' => $operation->error_code];
                }

                continue;
            }
            if ($tool !== null) {
                $tools[$tool] ??= self::blank();
                // Every projected end set the Tool's status and installed version, a cancel neither (ToolIntentProjection::projectState).
                // Only the terminal of a bound native run is projected: an expiry, a refused or lost start, or another Gateway-side failure
                // leaves the row as it was. Each field is stamped only by a receipt that changed it.
                $projected = $succeeded || (isset($state['swamp_run_id']) && ($operation->error_code === null
                    || str_starts_with((string) $operation->error_code, 'tool.') || $operation->error_code === 'operation.native_failed'));
                $version = is_string($result['installed_version'] ?? null) ? $result['installed_version'] : null;
                if ($projected && $operation->status !== 'cancelled') {
                    $tools[$tool]['stamp_state'] = $operation->id * 2;
                    if ($succeeded || $version !== null) {
                        $tools[$tool]['stamp_version'] = $operation->id * 2;
                    }
                }
                // A manager's state changed only with a manager version read or a failed setup.
                if ($projected && is_string($input['manager'] ?? null) && (is_string($result['manager_version'] ?? null) || ($operation->kind === 'tool.install'
                    && $operation->status === 'failed' && in_array($operation->error_code, ['tool.manager_provision_failed', 'tool.manager_unavailable'], true)))) {
                    $managers[$operation->node_id][$input['manager']] = $operation->id;
                }
                if ($operation->kind !== 'tool.remove' && $projected && in_array($operation->status, ['succeeded', 'failed', 'interrupted'], true)) {
                    $acquired = $succeeded && ($result['outcome'] ?? null) === 'applied';
                    if ($acquired) {
                        $tools[$tool]['stamp_owner'] = $operation->id * 2;
                    }
                    $tools[$tool] = [...$tools[$tool], 'owned' => $tools[$tool]['owned'] || $acquired,
                        'installed' => $acquired ? 'acquired' : ($succeeded ? ($tools[$tool]['installed'] ?? 'observed') : $tools[$tool]['installed']),
                        'last' => $succeeded ? 'installed' : 'failed', 'version' => $succeeded ? $version : ($version ?? $tools[$tool]['version'])];
                }
                // A removal whose run may have reached its package ended the claim, whether it failed, was cancelled, or ended after its
                // expiry; a successful one deleted the row. Only one that failed before its decision, whose decision refused, or whose
                // package step dpkg refused before any write left the package untouched.
                if ($operation->kind === 'tool.remove' && $ran && ! $succeeded
                    && ! $this->untouchedPackage(data_get($state, 'native_failure.failed_step'), $operation->error_code, data_get($state, 'native_failure.detail'))) {
                    $tools[$tool]['owned'] = false;
                    $tools[$tool]['stamp_owner'] = $operation->id * 2;
                }
                if ($operation->kind !== 'tool.remove') {
                    $latest[$tool] = ['operation' => $operation->id, 'repository' => $repository];
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
            // or capture left failed, or gives it an installed version other than the one its history last recorded. No admission captured
            // it, so it is newer than every Operation.
            $adopted = $history !== null && $tool->status === 'installed'
                && ($history['last'] === 'failed' || ($tool->installed_version !== null && $tool->installed_version !== $history['version']));
            $owned = $history === null ? $tool->status === 'installed' : ($history['owned'] || $adopted);
            // Each field keeps the order of the preceding release's newest receipt that changed it (Tool::receive, Tool::adopt). A late
            // receipt of an older Operation therefore never sets back a newer one.
            $receipts = $adopted ? array_fill_keys(['state', 'owner', 'version'], $latestOperation * 2 + 1)
                : array_filter(['state' => $history['stamp_state'] ?? null, 'owner' => $history['stamp_owner'] ?? null, 'version' => $history['stamp_version'] ?? null], 'is_int');
            $changes = ['owned' => $owned];
            if (isset($suspended[$tool->id])) {
                // The Tool waits for `tool:remove` with the expired removal's own code, as ToolIntentProjection::timedOut leaves it, unless
                // a newer receipt changed the field since (Tool::suspend).
                $order = $suspended[$tool->id]['operation'] * 2;
                $receipts['suspended'] = ['operation' => $suspended[$tool->id]['operation'], 'owned' => $owned, 'status' => $tool->status,
                    'failed_operation' => $tool->failed_operation, 'error_code' => $tool->error_code];
                if (($receipts['state'] ?? 0) <= $order) {
                    $changes = [...$changes, 'status' => 'failed', 'failed_operation' => 'remove', 'error_code' => $suspended[$tool->id]['code']];
                    $receipts['state'] = $order;
                }
                if (($receipts['owner'] ?? 0) <= $order) {
                    $changes['owned'] = false;
                    $receipts['owner'] = $order;
                }
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
            // The authority the Tool held before any removal of it set it aside: what the upgrade dispatches its queued work with.
            $claims[$tool->id] = $owned;
        }
        // The upgrade admits the preceding release's open work again under the ownership contract (docs/reference/tools.md#upgrade-from-the-preceding-release):
        // queued work runs with the authority the upgrade recorded, and a convergence's plans name it, so the current helper refuses a present
        // package Orbit does not own. A removal whose start was already sent ran under the preceding release, which removed whatever it found:
        // its end is read under that authority.
        foreach ($open as $id => $work) {
            $input = $work['input'];
            if ($work['kind'] === 'node.converge') {
                if ($work['ran']) {
                    continue;
                }
                $input = $this->withPlans($input, array_map(static fn (mixed $plan): mixed => is_array($plan) && is_int($plan['id'] ?? null)
                    ? [...$plan, 'owned' => $claims[$plan['id']] ?? false] : $plan, (array) data_get($input, '_converge.intent.tools', [])));
            } else {
                $input['package_owned'] = $work['kind'] === 'tool.remove' && $work['ran'] ? true
                    : (is_int($input['tool_id'] ?? null) && ($claims[$input['tool_id']] ?? false));
            }
            DB::table('operations')->where('id', $id)->update(['input' => json_encode($input, JSON_THROW_ON_ERROR)]);
        }
        foreach ($refused as $id => $toolId) {
            DB::table('operations')->where('id', $id)->update(['status' => 'failed', 'error_code' => 'tool.package_protected', 'lock_key' => null,
                'finished_at' => now(), 'current_step' => null, 'failed_step' => null]);
            // The installing record a refused first install left never became a Tool.
            if ($toolId !== null) {
                DB::table('tools')->where('id', $toolId)->where('status', 'installing')->delete();
            }
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
        if (! $ran || ! is_array($phase) || in_array($phase['status'] ?? null, ['unchanged', 'skipped'], true)) {
            return;
        }
        $installed = [];
        foreach ((array) ($result['tools'] ?? []) as $observation) {
            if (is_array($observation) && is_int($observation['id'] ?? null) && in_array($observation['status'] ?? null, ['installed', 'failed'], true)) {
                $toolId = $observation['id'];
                $tools[$toolId] ??= self::blank();
                $tools[$toolId]['last'] = $observation['status'];
                $tools[$toolId]['stamp_state'] = $id * 2;
                if ($observation['status'] === 'installed') {
                    $installed[$toolId] = true;
                    $tools[$toolId]['converged'] = $id;
                    $tools[$toolId]['installed'] ??= 'observed';
                    if (is_string($observation['installed_version'] ?? null)) {
                        $tools[$toolId]['version'] = $observation['installed_version'];
                        $tools[$toolId]['stamp_version'] = $id * 2;
                    }
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
        $history = $tools[$id] ?? self::blank();
        if (($status === 'installed' && $history['last'] === 'failed') || ($version !== null && $version !== $history['version'])) {
            $history = [...$history, 'owned' => true, 'installed' => 'adopted', 'last' => 'installed', 'version' => $version,
                'stamp_state' => $operation * 2 - 1, 'stamp_owner' => $operation * 2 - 1, 'stamp_version' => $operation * 2 - 1];
        }
        $tools[$id] = $history;
    }

    /** @return array{owned: bool, installed: ?string, last: ?string, version: ?string} */
    private static function blank(): array
    {
        return ['owned' => false, 'installed' => null, 'last' => null, 'version' => null];
    }

    /**
     * A convergence's input with these plans.
     *
     * @param  array<string, mixed>  $input
     * @param  array<mixed>  $plans
     * @return array<string, mixed>
     */
    private function withPlans(array $input, array $plans): array
    {
        $converge = is_array($input['_converge'] ?? null) ? $input['_converge'] : [];
        $intent = is_array($converge['intent'] ?? null) ? $converge['intent'] : [];

        return [...$input, '_converge' => [...$converge, 'intent' => [...$intent, 'tools' => $plans]]];
    }

    /**
     * The packages the current release protects on this Node for this manager (ToolPackageProtection), which its admission refuses.
     *
     * @return list<string>
     */
    private function protectedPackages(int $nodeId, string $manager): array
    {
        $name = ToolManagerName::tryFrom($manager);
        $node = Node::query()->find($nodeId);

        return $name === null || $node === null ? [] : $this->protection[$nodeId][$manager] ??= app(ToolPackageProtection::class)->forNode($node, $name);
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
     * A preceding-release removal that did not succeed left its package untouched only when it failed before its decision, its decision
     * refused (`tool.removal_plan_unsafe`, recorded at the end check), or its package step stopped on the dpkg lock, which apt and dpkg
     * take before any write. Any other end, a cancel or a late one included, may have reached the package.
     */
    private function untouchedPackage(mixed $failedStep, ?string $errorCode, mixed $detail): bool
    {
        return in_array($failedStep, ['Prepare package commands', 'Read manager version', 'Read installed package', 'Read macOS version', 'Plan the removal', 'Choose package action'], true)
            || ($failedStep === 'Assert package state' && $errorCode === 'tool.removal_plan_unsafe')
            || ($failedStep === 'Remove the package' && is_string($detail)
                && preg_match('~dpkg frontend lock (?:was|is) locked|Could not get lock /var/lib/dpkg/lock|dpkg status database is locked~', $detail) === 1);
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
