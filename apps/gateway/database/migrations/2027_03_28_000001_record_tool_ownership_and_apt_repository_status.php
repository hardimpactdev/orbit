<?php

declare(strict_types=1);

use App\Actions\Tools\StartToolOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptRepositoryOutcomes;
use App\Domain\Tools\PrecedingToolDecision;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records uninstall authority from the preceding release's receipts, replayed in Operation order, and gives every apt repository its own
 * state (docs/reference/tools.md#upgrade-from-the-preceding-release). Only an acquisition grants ownership; an observation or a membership
 * the user never asked for is no evidence. Each repository row the preceding release left keeps its name, definition and Tool references
 * and is not ready until its own `tool:apt-repository-add` succeeds: nothing in the history decides that. Queued work that never started
 * is admitted again by the current admission before its first dispatch; work that may have run settles under the preceding release's
 * authority.
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
        Schema::table('tool_managers', static function (Blueprint $table): void {
            // The newest Operation whose receipt changed the manager's state (ToolManagerRecord::receive).
            $table->unsignedBigInteger('receipt')->nullable();
        });
        Schema::table('apt_repositories', static function (Blueprint $table): void {
            // AptRepositoryStatus: ready only after the repository's own add succeeded.
            $table->string('status')->default('failed');
            $table->string('error_code')->nullable();
            // The newest Operation that may publish or remove the repository; only its outcome counts (AptRepositoryOutcomes::record).
            $table->unsignedBigInteger('operation_id')->nullable();
        });
        DB::table('apt_repositories')->update(['status' => 'failed', 'error_code' => 'tool.repository_unconfirmed']);

        // Per Tool: whether Orbit acquired it, how it became installed, the status its latest receipt left, the installed version that the
        // history itself explains, and the order of the newest receipt that changed each field.
        $tools = [];
        // The newest Operation that changed each Tool Manager's state, by Node and name, and each Tool's removal the preceding release
        // expired with its run unsettled, by Tool.
        $managers = [];
        $suspended = [];
        // Work still open at the upgrade whose start may have been sent, which the upgraded Gateway settles, by Operation: its kind and
        // input. Queued work that never started, which the current admission admits again before its first dispatch, in Operation order.
        $open = [];
        $queued = [];
        // Per Tool its admission made, the first install that made it: the preceding admission created the `installing` row for an install
        // that found no Tool, and captured its status as none (StartToolOperationAction).
        $createdBy = [];
        // Per Tool, when each Operation still open at the upgrade that names it was admitted, by Operation.
        $admittedOpen = [];
        $operations = DB::table('operations')->whereIn('kind', ['tool.install', 'tool.update', 'tool.remove', 'node.converge'])
            ->orderBy('id')->get(['id', 'kind', 'node_id', 'status', 'error_code', 'attempts', 'started_at', 'created_at', 'state', 'input', 'result']);
        foreach ($operations as $operation) {
            $input = $this->json($operation->input);
            $result = $this->json($operation->result);
            $state = $this->json($operation->state);
            // A start may have reached the Node once it was sent, also when its reply was lost: a bound native run or an attempt, an
            // Operation its start set running, or one the Gateway expired after a begin no deferral answered (StartSwampRun::startMayHaveBeenSent,
            // CancelOperationAction). A queued Operation never started, even one whose start Swamp deferred, and neither did one whose start
            // the Gateway or Swamp refused. Only Swamp verifying that no run exists says otherwise.
            $ran = ($operation->attempts > 0 || isset($state['swamp_run_id']) || in_array($operation->status, ['running', 'waiting'], true)
                || $operation->error_code === 'operation.timed_out') && StartSwampRun::startMayHaveBeenSent((new Operation)->newFromBuilder((array) $operation))
                && data_get($state, 'native_timeout.no_run_verified_at') === null;
            $succeeded = $operation->status === 'succeeded';
            // Work still open at the upgrade, queued, running, or expired by the preceding release with its run unsettled, is settled by
            // the upgraded Gateway when its end is read, and it changes no Tool here.
            $terminal = in_array(data_get($state, 'native_timeout.native_terminal_status'), ['succeeded', 'failed', 'cancelled', 'interrupted'], true)
                || data_get($state, 'native_timeout.no_run_verified_at') !== null;
            $expired = $operation->error_code === 'operation.timed_out' && ! $terminal;
            $isOpen = ! in_array($operation->status, ['succeeded', 'failed', 'cancelled', 'interrupted'], true) || $expired;
            if ($operation->kind === 'node.converge') {
                $plans = (array) data_get($input, '_converge.intent.tools', []);
                // Its admission captured each planned Tool's installed version and linked source (NodeDesiredConvergeInput).
                foreach ($plans as $plan) {
                    if (is_array($plan) && is_int($plan['id'] ?? null)) {
                        $linked = is_array($plan['repository'] ?? null) ? $plan['repository'] : null;
                        if ($isOpen) {
                            $admittedOpen[$plan['id']][$operation->id] = (string) $operation->created_at;
                        }
                        $this->capture($tools, $plan['id'], null, is_string($plan['installed_version'] ?? null) ? $plan['installed_version'] : null, $operation->id,
                            ['name' => is_string($linked['name'] ?? null) ? $linked['name'] : null]);
                    }
                }
                if ($isOpen && ! $ran) {
                    // Its other phases run as admitted; its Tools are admitted again by the current capture once the records stand.
                    // The preceding release captured Tools only for a full convergence, which also has its host release input.
                    if (($plans !== [] || data_get($input, '_converge.release_inputs.host') !== null) && $operation->status === 'queued') {
                        $queued[$operation->id] = $operation->kind;
                    }

                    continue;
                }
                if (! $isOpen) {
                    $this->converge($operation->id, $result, $ran, $tools);
                }

                continue;
            }
            $repository = is_array($input['repository'] ?? null) ? $input['repository'] : null;
            $tool = is_int($input['tool_id'] ?? null) ? $input['tool_id'] : null;
            $given = $operation->kind !== 'tool.remove' && ($input['repository_given'] ?? false) === true;
            if ($tool !== null) {
                // Admission captured the Tool's status and installed version (StartToolOperationAction), also for work still open, and the
                // source it linked: a removal's, or a write's that named none, is the linked record's. A write that named one shows nothing
                // of the link: the record it found by that name may be a sibling's.
                $link = ! $given ? ['name' => is_string($repository['name'] ?? null) ? $repository['name'] : null] : null;
                $this->capture($tools, $tool, is_string($input['tool_status'] ?? null) ? $input['tool_status'] : null,
                    is_string($input['installed_version'] ?? null) ? $input['installed_version'] : null, $operation->id, $link);
                if ($operation->kind === 'tool.install' && array_key_exists('tool_status', $input) && $input['tool_status'] === null) {
                    $createdBy[$tool] ??= $operation->id;
                }
                if ($isOpen) {
                    $admittedOpen[$tool][$operation->id] = (string) $operation->created_at;
                }
            }
            if ($isOpen && ! $ran) {
                // A queued request keeps its intent and is admitted again under the current rules before its first dispatch. One the Gateway
                // expired before it began ended without a run: it changes nothing.
                if ($operation->status === 'queued') {
                    $queued[$operation->id] = $operation->kind;
                }

                continue;
            }
            if ($isOpen) {
                $open[$operation->id] = ['kind' => $operation->kind, 'input' => $input, 'node_id' => $operation->node_id];
                // The preceding release did not set aside the authority of a removal it expired; the upgrade does, as the Gateway now
                // does at expiry, until the run's end is read or Swamp verifies it never ran.
                if ($expired && $operation->kind === 'tool.remove' && $tool !== null) {
                    $suspended[$tool] = ['operation' => $operation->id, 'code' => $operation->error_code];
                }

                continue;
            }
            if ($tool === null) {
                continue;
            }
            $tools[$tool] ??= self::blank();
            // Every projected end set the Tool's status and installed version, a cancel neither (ToolIntentProjection::projectState). Only
            // the terminal of a bound native run is projected: an expiry, a refused or lost start, or another Gateway-side failure leaves the
            // row as it was. Each field is stamped only by a receipt that changed it.
            $projected = $succeeded || (isset($state['swamp_run_id']) && ($operation->error_code === null
                || str_starts_with((string) $operation->error_code, 'tool.') || $operation->error_code === 'operation.native_failed'));
            $version = is_string($result['installed_version'] ?? null) ? $result['installed_version'] : null;
            if ($projected && $operation->status !== 'cancelled') {
                $tools[$tool]['stamp_state'] = $operation->id * 2;
                // Only a success, or a failure whose decision read the package, observed the installed version: the one it read, absence
                // included. A failure that read nothing recorded the admitted version again. A receipt without the field shows nothing.
                $failedStep = data_get($state, 'native_failure.failed_step');
                $reading = $succeeded ? ['version' => $version] : (in_array($failedStep, ['Apply the package', 'Check apt package', 'Check the package', 'Decode package state', 'Assert package state'], true)
                    && array_key_exists('installed_version', $result)
                    ? PrecedingToolDecision::reading($operation->kind, is_string($input['manager'] ?? null) ? $input['manager'] : '', is_string($input['platform'] ?? null) ? $input['platform'] : '', $failedStep !== 'Assert package state',
                        $operation->error_code, $version, is_string($input['installed_version'] ?? null) ? $input['installed_version'] : null) : null);
                if ($reading !== null) {
                    $tools[$tool]['stamp_version'] = $operation->id * 2;
                    $tools[$tool]['absent'] = $reading['version'] === null ? $operation->id : null;
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
                // A success linked the Tool to the source it wrote with, or to none.
                if ($succeeded) {
                    $tools[$tool]['link'] = is_string($repository['name'] ?? null) ? $repository['name'] : null;
                }
            }
            // A removal whose run may have reached its package ended the claim, whether it failed, was cancelled, or ended after its expiry;
            // a successful one deleted the row. Only one that failed before its decision, whose decision refused, or whose package step dpkg
            // refused before any write left the package untouched.
            if ($operation->kind === 'tool.remove' && $ran && ! $succeeded
                && ! $this->untouchedPackage(data_get($state, 'native_failure.failed_step'), $operation->error_code, data_get($state, 'native_failure.detail'))) {
                $tools[$tool]['owned'] = false;
                $tools[$tool]['stamp_owner'] = $operation->id * 2;
            }
        }

        $latestOperation = ValidatedData::integer(DB::table('operations')->max('id') ?? 0);
        foreach (DB::table('tools')->get(['id', 'node_id', 'tool_manager_id', 'status', 'installed_version', 'failed_operation', 'apt_repository_id', 'updated_at', 'error_code']) as $tool) {
            $history = $tools[$tool->id] ?? null;
            // Only an adoption, which the preceding release recorded without an Operation, makes a Tool installed that its latest receipt
            // or capture left failed, or gives it an installed version other than the one its history last recorded. A Tool linked to a
            // source other than the one its last success linked was adopted from that source.
            $linked = $tool->apt_repository_id === null ? null : DB::table('apt_repositories')->where('id', $tool->apt_repository_id)->value('name');
            $adopted = $history !== null && $tool->status === 'installed'
                && ($history['last'] === 'failed' || ($tool->installed_version !== null && $tool->installed_version !== $history['version'])
                    || (array_key_exists('link', $history) && $linked !== $history['link']));
            $owned = $history === null ? $tool->status === 'installed' : ($history['owned'] || $adopted);
            // An adoption no admission captured left the row as it stands: it came before every open Operation of the Tool admitted after the
            // row's last write, and otherwise after every Operation. A Tool with no history at all was adopted the same way.
            $adoptedAt = $latestOperation * 2 + 1;
            foreach (is_array($admittedOpen[$tool->id] ?? null) ? $admittedOpen[$tool->id] : [] as $operation => $admitted) {
                if (is_string($tool->updated_at) && $tool->updated_at < $admitted) {
                    $adoptedAt = min($adoptedAt, (int) $operation * 2 - 1);
                }
            }
            // Each field keeps the order of the preceding release's newest receipt that changed it (Tool::receive, Tool::adopt). A late
            // receipt of an older Operation therefore never sets back a newer one.
            $receipts = $adopted ? array_fill_keys(['state', 'owner', 'version'], $adoptedAt)
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
            // The newest reading of the version was the package's absence, which the preceding release recorded as the admitted version.
            if (! $adopted && is_int($history['absent'] ?? null) && ($receipts['version'] ?? null) === $history['absent'] * 2) {
                $changes['installed_version'] = null;
            }
            $manager = DB::table('tool_managers')->where('id', $tool->tool_manager_id)->first(['node_id', 'name']);
            $convergeStamp = $history['converged'] ?? null;
            if ($manager !== null && is_int($convergeStamp)) {
                $managers[$manager->node_id][$manager->name] = max($managers[$manager->node_id][$manager->name] ?? 0, $convergeStamp);
            }
            DB::table('tools')->where('id', $tool->id)->update($changes);
        }
        foreach ($managers as $nodeId => $byName) {
            foreach ($byName as $name => $operation) {
                DB::table('tool_managers')->where('node_id', $nodeId)->where('name', $name)->update(['receipt' => $operation]);
            }
        }

        // Work whose start may have been sent ran under the preceding release, which removed whatever its removal found: its end is read
        // under that authority (docs/reference/tools.md#upgrade-from-the-preceding-release). A write's end links its Tool to the repository
        // row its source names, which stays not ready.
        foreach ($open as $id => $work) {
            $input = [...$work['input'], 'package_owned' => $work['kind'] === 'tool.remove'];
            $name = is_array($input['repository'] ?? null) ? ($input['repository']['name'] ?? null) : null;
            $input['repository_id'] = is_string($name) ? DB::table('apt_repositories')->where('node_id', $work['node_id'])->where('name', $name)->value('id') : null;
            DB::table('operations')->where('id', $id)->update(['input' => json_encode($input, JSON_THROW_ON_ERROR)]);
        }
        // Queued work that never started is admitted again, in place and in order, by the current admission before its first dispatch, as
        // if admitted now: a direct request (StartToolOperationAction::readmit), or a convergence's Tools (ToolConvergeIntent::capture, as
        // StartNodeConvergeAction admits them), its other phases as admitted. Still-valid intent keeps its Operation and idempotency key;
        // a refusal ends the Operation with the refusal's code and nothing run.
        // In queue order, each as it was admitted then: work queued earlier, and work that may have run, hold their scope against it, and
        // work queued later is not there yet. So a later request that overlaps an earlier one is refused, as its own admission would have
        // been, and no two contradictory plans are dispatched.
        foreach ($queued as $id => $kind) {
            $operation = Operation::query()->findOrFail($id);
            $later = array_values(array_filter(array_keys($queued), static fn (int $other): bool => $other > $id));
            try {
                if ($kind === 'node.converge') {
                    // A full convergence (the preceding release captured Tools only for one) owns the Tools scope whatever it plans, as
                    // StartNodeConvergeAction records it. Only the placeholder a later queued first install itself made is not there yet.
                    // Every other membership stays, an adopted Tool's included, also when its first direct request is a later reinstall, and
                    // the writer scope and pins follow that same set.
                    $node = Node::query()->findOrFail($operation->node_id);
                    $capture = app(ToolConvergeIntent::class);
                    $plans = $capture->capture($node, $id, null, $later,
                        array_keys(array_filter($createdBy, static fn (int $install): bool => in_array($install, $later, true))));
                    $repositories = $capture->repositories($plans);
                    $input = $this->withPlans($operation->input, $plans, $repositories);
                    data_set($input, '_converge.tool_scope', true);
                    $operation->forceFill(['input' => $input])->save();
                    AptRepositoryOutcomes::claim(array_column($repositories, 'id'), $id);
                    ToolConvergeIntent::admitDesired($plans);
                } else {
                    app(StartToolOperationAction::class)->readmit($operation, $later);
                }
            } catch (ResourceOperationException|ToolOperationException $refusal) {
                $operation->refresh()->forceFill(['status' => 'failed', 'lock_key' => null, 'finished_at' => now(), 'current_step' => null, 'failed_step' => null,
                    'result' => ['error_code' => $refusal->errorCode], 'error_code' => $refusal->errorCode])->save();
                // A refused first install leaves no membership: the installing record its own admission made goes.
                $toolId = $operation->input['tool_id'] ?? null;
                if ($kind === 'tool.install' && is_int($toolId) && ($createdBy[$toolId] ?? null) === $id) {
                    DB::table('tools')->where('id', $toolId)->where('status', 'installing')->delete();
                }
            }
        }
    }

    /**
     * A converge whose Tools phase ran judged each planned Tool; one that skipped the phase changed nothing. Its capture recorded each
     * Tool's installed version (NodeDesiredConvergeInput). The preceding release kept no per-Tool write count, so an installed observation
     * is an observation unless the Tool was owned.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function converge(int $id, array $result, bool $ran, array &$tools): void
    {
        $phase = collect((array) ($result['phases'] ?? []))->firstWhere('name', 'tools');
        if (! $ran || ! is_array($phase) || in_array($phase['status'] ?? null, ['unchanged', 'skipped'], true)) {
            return;
        }
        foreach ((array) ($result['tools'] ?? []) as $observation) {
            if (is_array($observation) && is_int($observation['id'] ?? null) && in_array($observation['status'] ?? null, ['installed', 'failed'], true)) {
                $toolId = $observation['id'];
                $tools[$toolId] ??= self::blank();
                $tools[$toolId]['last'] = $observation['status'];
                $tools[$toolId]['stamp_state'] = $id * 2;
                if ($observation['status'] === 'installed') {
                    $tools[$toolId]['converged'] = $id;
                    $tools[$toolId]['installed'] ??= 'observed';
                    if (is_string($observation['installed_version'] ?? null)) {
                        $tools[$toolId]['version'] = $observation['installed_version'];
                        $tools[$toolId]['stamp_version'] = $id * 2;
                    }
                }
            }
        }
    }

    /**
     * A capture of a Tool's status and installed version, at an admission or a converge. Only an adoption, which left no Operation,
     * makes a Tool installed after its latest receipt left it failed, or gives it a version other than the one its history last
     * recorded: every receipt the preceding release projected, an interrupted one included, set that version.
     *
     * An admission that captured the source the Tool linked shows an adoption too when that source is not the one its history last
     * linked. The adoption orders just before the capturing Operation.
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array{name: ?string}|null  $link  the linked source the admission captured, when it shows one
     */
    private function capture(array &$tools, int $id, ?string $status, ?string $version, int $operation, ?array $link = null): void
    {
        $history = $tools[$id] ?? self::blank();
        $relinked = $link !== null && array_key_exists('link', $history) && $history['link'] !== $link['name'];
        if (($status === 'installed' && $history['last'] === 'failed') || ($version !== null && $version !== $history['version']) || $relinked) {
            $history = [...$history, 'owned' => true, 'installed' => 'adopted', 'last' => 'installed', 'version' => $version ?? $history['version'],
                'stamp_state' => $operation * 2 - 1, 'stamp_owner' => $operation * 2 - 1, 'stamp_version' => $operation * 2 - 1];
        }
        if ($link !== null) {
            $history['link'] = $link['name'];
        }
        $tools[$id] = $history;
    }

    /** @return array{owned: bool, installed: ?string, last: ?string, version: ?string} */
    private static function blank(): array
    {
        return ['owned' => false, 'installed' => null, 'last' => null, 'version' => null];
    }

    /**
     * A convergence's input with these plans and the repositories they reference.
     *
     * @param  array<string, mixed>  $input
     * @param  array<mixed>  $plans
     * @param  list<array<string, mixed>>  $repositories
     * @return array<string, mixed>
     */
    private function withPlans(array $input, array $plans, array $repositories): array
    {
        $converge = is_array($input['_converge'] ?? null) ? $input['_converge'] : [];
        $intent = is_array($converge['intent'] ?? null) ? $converge['intent'] : [];

        return [...$input, '_converge' => [...$converge, 'intent' => [...$intent, 'tools' => $plans, 'repositories' => $repositories]]];
    }

    /**
     * A preceding-release removal that did not succeed left its package untouched only when it failed before its decision, its decision
     * refused (`tool.removal_plan_unsafe`, recorded at the end check), or its package step was dpkg refusing to start on its lock. Any other end, a cancel or a late one included, may have reached the package.
     */
    private function untouchedPackage(mixed $failedStep, ?string $errorCode, mixed $detail): bool
    {
        return in_array($failedStep, ['Prepare package commands', 'Read manager version', 'Read installed package', 'Read macOS version', 'Plan the removal', 'Choose package action'], true)
            || ($failedStep === 'Assert package state' && $errorCode === 'tool.removal_plan_unsafe')
            || ($failedStep === 'Remove the package' && is_string($detail) && $this->lockRefusal($detail));
    }

    /**
     * dpkg's own refusal to start: its whole diagnostic is one frontend or database lock line and dpkg's note on it. A nested dpkg that
     * a trigger runs after the removal prints the same line, followed by the error processing the triggering package.
     */
    private function lockRefusal(string $detail): bool
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $detail)), static fn (string $line): bool => $line !== ''));

        return preg_match('~\Adpkg: error: dpkg (?:frontend|database) lock was locked by .+\z~', $lines[0] ?? '') === 1
            && array_slice($lines, 1) === ['Note: removing the lock file is always wrong, can damage the locked area', 'and the entire system. See <https://wiki.debian.org/Teams/Dpkg/FAQ#db-lock>.'];
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
            $table->dropColumn(['status', 'error_code', 'operation_id']);
        });
        Schema::table('tools', static function (Blueprint $table): void {
            $table->dropColumn(['owned', 'receipts']);
        });
        Schema::table('tool_managers', static function (Blueprint $table): void {
            $table->dropColumn('receipt');
        });
    }
};
