<?php

declare(strict_types=1);

use App\Actions\Tools\StartToolOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptSourceTrust;
use App\Domain\Tools\PrecedingToolDecision;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolDefinition;
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
 * Records uninstall authority, apt source trust and source cleanup from the preceding release's receipts, replayed in Operation order
 * (docs/reference/tools.md#upgrade-from-the-preceding-release). Only an acquisition grants ownership and only a write that ran grants
 * trust; an observation, a skipped write or a membership the user never asked for is no evidence. Each source that may still be on disk
 * keeps its own record, which a full convergence removes once no Tool names it. Queued work that never started is admitted again by the
 * current admission before its first dispatch; work that may have run settles under the preceding release's authority.
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
        // Work still open at the upgrade whose start may have been sent, which the upgraded Gateway settles, by Operation: its kind and
        // input. Queued work that never started, which the current admission admits again before its first dispatch, in Operation order.
        $open = [];
        $queued = [];
        $desired = [];
        // Per Tool its admission made, the first install that made it: the preceding admission created the `installing` row for an install
        // that found no Tool, and captured its status as none (StartToolOperationAction).
        $createdBy = [];
        // Per Tool, when each Operation still open at the upgrade that names it was admitted, by Operation.
        $admittedOpen = [];
        // Per Tool linked to a record, the order of its adoption, when it was adopted.
        $adoptions = [];
        // Per Tool, the record each open write that named a source found when admitted, by Operation.
        $found = [];
        // Per open Operation, when it was admitted, the record its admission found and the packages of every Tool linked to that record then,
        // its own included (StartToolOperationAction: the source's pins).
        $pinned = [];
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
            // the upgraded Gateway when its end is read: its sources keep it as an open writer, and it changes no Tool here.
            $terminal = in_array(data_get($state, 'native_timeout.native_terminal_status'), ['succeeded', 'failed', 'cancelled', 'interrupted'], true)
                || data_get($state, 'native_timeout.no_run_verified_at') !== null;
            $expired = $operation->error_code === 'operation.timed_out' && ! $terminal;
            $isOpen = ! in_array($operation->status, ['succeeded', 'failed', 'cancelled', 'interrupted'], true) || $expired;
            if ($operation->kind === 'node.converge') {
                $plans = (array) data_get($input, '_converge.intent.tools', []);
                // Its admission captured each planned Tool's installed version and linked source (NodeDesiredConvergeInput): the plan's
                // repository is the linked record's definition.
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
                if ($isOpen) {
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
            $given = $operation->kind !== 'tool.remove' && ($input['repository_given'] ?? false) === true;
            if ($tool !== null) {
                // Admission captured the Tool's status and installed version (StartToolOperationAction), also for work still open, and the
                // source it linked: a removal's, or a write's that named none, is the linked record's definition. A write that named one
                // shows nothing of the link: the record it found by that name may be a sibling's.
                $link = ! $given ? ['name' => is_string($repository['name'] ?? null) ? $repository['name'] : null] : null;
                $this->capture($tools, $tool, is_string($input['tool_status'] ?? null) ? $input['tool_status'] : null,
                    is_string($input['installed_version'] ?? null) ? $input['installed_version'] : null, $operation->id, $link);
            }
            if ($tool !== null) {
                if ($operation->kind === 'tool.install' && array_key_exists('tool_status', $input) && $input['tool_status'] === null) {
                    $createdBy[$tool] ??= $operation->id;
                }
                if ($isOpen) {
                    $admittedOpen[$tool][$operation->id] = (string) $operation->created_at;
                    // The record a write that named a source found under that name when admitted (StartToolOperationAction).
                    if ($given && is_int($input['repository_id'] ?? null)) {
                        $found[$tool][$operation->id] = $input['repository_id'];
                    }
                    if (is_int($input['repository_id'] ?? null)) {
                        $pinned[$operation->id] = ['record' => $input['repository_id'], 'tool' => $tool, 'admitted' => (string) $operation->created_at,
                            'packages' => array_values(array_filter((array) ($input['repository_packages'] ?? []), 'is_string'))];
                    }
                }
            }
            // A request still pending names the Tool's desired source, as a failed one does. A queued one never started: its admission
            // again decides what it desires.
            if ($tool !== null && $operation->kind !== 'tool.remove' && $isOpen && $ran) {
                $latest[$tool] = ['operation' => $operation->id, 'repository' => $repository];
            }
            if ($isOpen && ! $ran) {
                // A queued request keeps its intent and is admitted again under the current rules before its first dispatch. One the Gateway
                // expired before it began ended without a run: it wrote nothing and changes nothing.
                if ($operation->status === 'queued') {
                    $queued[$operation->id] = $operation->kind;
                }

                continue;
            }
            if ($isOpen) {
                $open[$operation->id] = ['kind' => $operation->kind, 'input' => $input, 'node_id' => $operation->node_id];
                // Only a run that could write the source is its writer: the preceding write workflow publishes only with `repository_given`,
                // and its removal workflow touches the source only as the last member's cleanup.
                $writes = $operation->kind === 'tool.remove' ? ($input['remove_repository'] ?? false) === true : $given;
                // Desired state belongs to the newest admission: a write that named a source asked for it (ToolConvergeIntent::admitDesired).
                if ($given && $tool !== null) {
                    $desired[$tool] = ['operation' => $operation->id, 'repository' => $repository];
                }
                if ($repository !== null && is_string($repository['name'] ?? null) && $writes) {
                    $this->writer($sources, $operation->node_id, $repository['name'], $operation->id, $repository);
                }
                // The preceding release did not set aside the authority of a removal it expired; the upgrade does, as the Gateway now
                // does at expiry, until the run's end is read or Swamp verifies it never ran.
                if ($expired && $operation->kind === 'tool.remove' && $tool !== null) {
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
                    // Only a success, or a failure whose decision read the package, observed the installed version: the one it read, absence
                    // included. A failure that read nothing recorded the admitted version again.
                    $failedStep = data_get($state, 'native_failure.failed_step');
                    // A receipt that recorded no version field shows nothing of what was read.
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
            $this->note($sources, $operation->node_id, $name, $repository, $event, $operation->id, is_int($input['repository_id'] ?? null) ? $input['repository_id'] : null);
        }

        $latestOperation = ValidatedData::integer(DB::table('operations')->max('id') ?? 0);
        foreach (DB::table('tools')->get(['id', 'node_id', 'tool_manager_id', 'package', 'status', 'installed_version', 'failed_operation', 'apt_repository_id', 'desired_repository', 'updated_at', 'error_code']) as $tool) {
            $history = $tools[$tool->id] ?? null;
            // Only an adoption, which the preceding release recorded without an Operation, makes a Tool installed that its latest receipt
            // or capture left failed, or gives it an installed version other than the one its history last recorded. No admission captured
            // it, so it is newer than every Operation.
            // A Tool linked to a source other than the one its last success linked was adopted from that source.
            $linked = $tool->apt_repository_id === null ? null : DB::table('apt_repositories')->where('id', $tool->apt_repository_id)->value('name');
            $adopted = $history !== null && $tool->status === 'installed'
                && ($history['last'] === 'failed' || ($tool->installed_version !== null && $tool->installed_version !== $history['version'])
                    || (array_key_exists('link', $history) && $linked !== $history['link']));
            $owned = $history === null ? $tool->status === 'installed' : ($history['owned'] || $adopted);
            // An adoption no admission captured left the row as it stands: it came before every open Operation of the Tool admitted after the
            // row's last write, and otherwise after every Operation. A Tool with no history at all was adopted the same way.
            // What an admission captured shows the membership at that moment, not that the row's newest adoption came before it: a row
            // last written in a later second, by a newer adoption or as a replacement row, is newer than that admission. Within the row's
            // own second, a capture orders the adoption:
            // - A write that found the very record this Tool links, a record only this Tool's adoption made (no Operation of the history
            //   made it, no other Tool links it), was admitted after that adoption.
            // - Another Tool's open admission that found this record pinned this Tool's package (StartToolOperationAction pins every Tool
            //   linked to the record): the Tool was linked to it then, and when its history never linked the source, only its adoption
            //   linked it.
            $adoptedAt = $latestOperation * 2 + 1;
            $written = is_string($tool->updated_at) ? $tool->updated_at : null;
            foreach (is_array($admittedOpen[$tool->id] ?? null) ? $admittedOpen[$tool->id] : [] as $operation => $admitted) {
                if (($written !== null && $written < $admitted)
                    || ($written !== null && $written <= $admitted && is_int($tool->apt_repository_id) && ($found[$tool->id][$operation] ?? null) === $tool->apt_repository_id
                        && $this->madeByAdoption($tool->id, $tool->node_id, $tool->apt_repository_id, $sources))) {
                    $adoptedAt = min($adoptedAt, (int) $operation * 2 - 1);
                }
            }
            if (is_int($tool->apt_repository_id) && $written !== null && ($history === null || ($history['link'] ?? null) !== $linked)) {
                foreach ($pinned as $operation => $pins) {
                    if ($pins['record'] === $tool->apt_repository_id && $pins['tool'] !== $tool->id && $written <= $pins['admitted']
                        && in_array($tool->package, $pins['packages'], true)) {
                        $adoptedAt = min($adoptedAt, (int) $operation * 2 - 1);
                    }
                }
            }
            if ($tool->apt_repository_id !== null && ($adopted || ($history === null && $tool->status === 'installed'))) {
                $adoptions[$tool->apt_repository_id][] = $adoptedAt;
            } elseif ($tool->apt_repository_id !== null && ($history['installed'] ?? null) === 'adopted') {
                $adoptions[$tool->apt_repository_id][] = ValidatedData::integer($history['stamp_owner']);
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
            // Unless a newer adoption named the Tool's source since, a removal confirmed that source gone after the request (as for a failed
            // one), or another membership defines the shared source differently, which the current admission refuses.
            $asked = $desired[$tool->id] ?? null;
            $askedName = $asked['repository']['name'] ?? null;
            $adoptedSince = ($adopted && $adoptedAt > ($asked['operation'] ?? 0) * 2)
                || (($history['installed'] ?? null) === 'adopted' && ($history['stamp_owner'] ?? 0) > ($asked['operation'] ?? 0) * 2);
            if ($asked !== null && is_string($askedName) && ! $adoptedSince
                && max([0, ...ValidatedData::integerList($sources[$tool->node_id][$askedName]['removals'] ?? [])]) < $asked['operation']
                && $this->agrees($tool->id, $tool->node_id, $askedName, ValidatedData::object($asked['repository']))) {
                $changes['desired_repository'] = json_encode($asked['repository'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
                // A confirmed removal withdrew the source from every writer admitted before it, as AptSourceTrust::removed does: only a
                // newer one may have published it again.
                $withdrawnAt = max([0, ...ValidatedData::integerList($last['removals'] ?? [])]);
                $all = is_array($last['writers'] ?? null) ? $last['writers'] : [];
                $last['writers'] = array_filter($all, static fn (int|string $writer): bool => (int) $writer > $withdrawnAt, ARRAY_FILTER_USE_KEY);
                // A write withdrawn so never brings the source back: its end, read later, has no source to settle or link.
                foreach (array_diff_key($all, $last['writers']) as $writer => $unused) {
                    if (isset($open[$writer]) && $open[$writer]['kind'] !== 'tool.remove') {
                        $open[$writer]['input'] = [...$open[$writer]['input'], 'repository' => null, 'repository_id' => null, 'repository_given' => false];
                    }
                }
                $sources[$nodeId][$name] = $last;
                $record = DB::table('apt_repositories')->where('node_id', $nodeId)->where('name', $name)->first(['id']);
                if ($record === null && ((isset($last['event']) && $last['event'] !== 'removed') || $last['writers'] !== [])) {
                    // A source that may still be on disk keeps its own record, whatever package names collide. After a confirmed removal
                    // only a newer writer's definition may be on disk, never the one removed.
                    $definition = ($last['event'] ?? null) !== 'removed' && isset($last['definition']) ? $last['definition'] : array_values($last['writers'])[count($last['writers']) - 1];
                    DB::table('apt_repositories')->insert(['node_id' => $nodeId, 'name' => $name, 'verified' => false, 'created_at' => now(),
                        'updated_at' => now(), 'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
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
            // A definition no event or open writer of the history explains is an adoption's, which left no Operation: as AptSourceTrust::adopt,
            // it settles unproven, newer than every Operation, so no late end of older work gives trust to another definition.
            $known = [...(isset($last['definition']) ? [$last['definition']] : []), ...array_values(is_array($last['writers'] ?? null) ? $last['writers'] : []),
                ...(array) ($last['possible'] ?? [])];
            $adopted = $last !== [] && ! in_array($definition, $known);
            // The newest adoption of a Tool linked to this record set that definition: it orders as that Tool's adoption does, and after
            // every Operation when no Tool's adoption shows when it came.
            $adoptedAt = max([0, ...(is_array($adoptions[$record->id] ?? null) ? $adoptions[$record->id] : [])]);
            // As AptSourceTrust::recompute: trust holds while no writer newer than the settled end is open.
            $settledAt = $adopted ? ($adoptedAt === 0 ? $latestOperation : intdiv($adoptedAt + 1, 2) - 1) : (is_int($last['operation'] ?? null) ? $last['operation'] : 0);
            $newer = array_filter(array_keys($writers), static fn (int|string $writer): bool => (int) $writer > $settledAt) !== [];
            $written = $written && ! $adopted;
            DB::table('apt_repositories')->where('id', $record->id)->update(['verified' => $written && ! $newer,
                'entries' => json_encode($possible, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'writers' => json_encode((object) $writers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'settled' => json_encode(['verified' => $written, 'definition' => $definition, 'entries' => $entries, 'operation' => $settledAt], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
        // Work whose start may have been sent ran under the preceding release, which removed whatever its removal found: its end is read
        // under that authority (docs/reference/tools.md#upgrade-from-the-preceding-release), against the record its source has now.
        foreach ($open as $id => $work) {
            $input = [...$work['input'], 'package_owned' => $work['kind'] === 'tool.remove'];
            $name = is_array($input['repository'] ?? null) ? ($input['repository']['name'] ?? null) : null;
            if (is_string($name)) {
                $input['repository_id'] = DB::table('apt_repositories')->where('node_id', $work['node_id'])->where('name', $name)->value('id');
            }
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
                    // A full convergence (the preceding release captured Tools only for one) owns the Tools scope and the orphan cleanup
                    // whatever it plans, as StartNodeConvergeAction records it. Only the placeholder a later queued first install itself made
                    // is not there yet. Every other membership stays, an adopted Tool's included, also when its first direct request is a
                    // later reinstall, and the writer scope and pins follow that same set.
                    $node = Node::query()->findOrFail($operation->node_id);
                    $plans = app(ToolConvergeIntent::class)->capture($node, $id, null, $later,
                        array_keys(array_filter($createdBy, static fn (int $install): bool => in_array($install, $later, true))));
                    $input = $this->withPlans($operation->input, $plans);
                    data_set($input, '_converge.tool_scope', true);
                    data_set($input, '_converge.intent.orphan_sources', AptSourceTrust::orphans($node->id));
                    $operation->forceFill(['input' => $input])->save();
                    AptSourceTrust::claim($node->id, AptSourceTrust::planned($plans), $id);
                    ToolConvergeIntent::admitDesired($plans);
                } else {
                    app(StartToolOperationAction::class)->readmit($operation, $later);
                }
            } catch (ResourceOperationException|ToolOperationException $refusal) {
                $operation->refresh()->forceFill(['status' => 'failed', 'lock_key' => null, 'finished_at' => now(), 'current_step' => null, 'failed_step' => null,
                    'result' => ['error_code' => $refusal->errorCode], 'error_code' => $refusal->errorCode])->save();
                // A refused first install leaves no membership: the installing record its own admission made goes, and its source is
                // desired by nothing.
                $toolId = $operation->input['tool_id'] ?? null;
                if ($kind === 'tool.install' && is_int($toolId) && ($createdBy[$toolId] ?? null) === $id) {
                    DB::table('tools')->where('id', $toolId)->where('status', 'installing')->delete();
                }
            }
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
                $this->note($sources, $nodeId, $name, $repository, isset($installed[ValidatedData::integer($plan['id'])]) ? 'written' : 'uncertain', $id, null);
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
     * The Tool's linked record can only have been made by its own adoption: no Operation of the history made it (no event under its name,
     * or the newest one confirmed the removal of another record under it), and no other Tool links it.
     *
     * @param  array<int, array<string, array<string, mixed>>>  $sources
     */
    private function madeByAdoption(int $toolId, int $nodeId, int $recordId, array $sources): bool
    {
        $name = DB::table('apt_repositories')->where('id', $recordId)->value('name');
        $source = is_string($name) ? ($sources[$nodeId][$name] ?? []) : [];

        return is_string($name) && (! isset($source['event']) || ($source['event'] === 'removed' && ($source['record'] ?? null) !== $recordId))
            && ! DB::table('tools')->where('apt_repository_id', $recordId)->where('id', '!=', $toolId)->exists();
    }

    /**
     * Every other membership on the Node that names this source defines it as `$definition` (ToolDefinitionResolver::assertSharedRepository).
     *
     * @param  array<string, mixed>  $definition
     */
    private function agrees(int $toolId, int $nodeId, string $name, array $definition): bool
    {
        foreach (DB::table('tools')->leftJoin('apt_repositories', 'apt_repositories.id', '=', 'tools.apt_repository_id')->where('tools.node_id', $nodeId)
            ->where('tools.id', '!=', $toolId)->get(['tools.status', 'tools.failed_operation', 'tools.desired_repository', 'apt_repositories.definition']) as $member) {
            $shared = $this->json($member->desired_repository) ?: $this->json($member->definition);
            $withdrawn = $member->status === 'failed' && $member->failed_operation === 'remove';
            if (! $withdrawn && ($shared['name'] ?? null) === $name && ! ToolDefinition::sameRepository($shared, $definition)) {
                return false;
            }
        }

        return true;
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

    /**
     * A source's latest event, the record its Operation found, and its definition, every definition it may have on disk since its last confirmed write or removal, and
     * the Operations that confirmed its removal.
     *
     * @param  array<int, array<string, array<string, mixed>>>  $sources
     * @param  array<mixed>  $repository
     */
    private function note(array &$sources, int $nodeId, string $name, array $repository, ?string $event, int $operation, ?int $record): void
    {
        if ($event === null) {
            return;
        }
        // An open writer may have made the entry first, with neither list.
        $entry = $sources[$nodeId][$name] ?? [];
        $entry['possible'] = match ($event) {
            'written' => [$repository], 'removed' => [], default => [...(array) ($entry['possible'] ?? []), $repository],
        };
        $entry['removals'] = $event === 'removed' ? [...(array) ($entry['removals'] ?? []), $operation] : (array) ($entry['removals'] ?? []);
        $sources[$nodeId][$name] = [...$entry, 'event' => $event, 'operation' => $operation, 'record' => $record, 'definition' => $repository];
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
