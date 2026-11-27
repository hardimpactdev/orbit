<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationLateResult;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\AptRepository;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Public intent and bounded state writes. Swamp owns package execution and policy. */
final readonly class ToolIntentProjection implements DirectOperationLateResult, DirectOperationProjection, DirectOperationTimeout, NativeOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ['kind' => $operation->kind, ...array_intersect_key($operation->input, array_flip([
            'node_id', 'manager', 'package', 'user', 'platform', 'architecture', 'version_constraint', 'desired_version', 'tool_id', 'tool_status', 'installed_version', 'setup', 'repository', 'repository_packages', 'manager_ready',
        ])), 'package_owned' => $this->packageOwned($operation)], 'ssh' => $operation->input['ssh']];
    }

    /**
     * The uninstall authority the run was given: the one its admission recorded, or, for work the preceding release left open, the one the
     * upgrade recorded for it (docs/reference/tools.md#upgrade-from-the-preceding-release). Its receipt is read under the same authority,
     * whatever the Tool holds by then.
     */
    private function packageOwned(Operation $operation): bool
    {
        return ($operation->input['package_owned'] ?? false) === true;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $public = ValidatedData::object($data['result'] ?? []);
        $result = $this->nativeResult($operation, $data, $public);
        $evidence = $this->evidence($operation, $public);
        $data['error_code'] = $result['error_code'] ?? ($data['error_code'] ?? null);
        $result = array_intersect_key($result, array_flip([
            'native_error_code', 'manager_version', 'installed_version', 'installed_coordinate', 'outcome', 'block', 'error_code',
        ]));
        foreach (['manager_version', 'installed_version', 'installed_coordinate'] as $field) {
            if (isset($result[$field]) && (! is_string($result[$field]) || $result[$field] === '' || strlen($result[$field]) > 255 || preg_match('/[\x00-\x1F\x7F]/', $result[$field]) === 1)) {
                throw new ResourceOperationException('operation.result_invalid', 'The Tool version is invalid.', 422);
            }
        }
        foreach (['native_error_code', 'error_code', 'block'] as $field) {
            if (isset($result[$field]) && (! is_string($result[$field]) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $result[$field]) !== 1)) {
                throw new ResourceOperationException('operation.result_invalid', 'The Tool refusal is invalid.', 422);
            }
        }
        if (isset($result['outcome']) && ! in_array($result['outcome'], ['applied', 'unchanged', 'blocked_by_constraint'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'The Tool outcome is invalid.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $evidence): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, array_diff_key($result, ['installed_coordinate' => true]));
            if (! $terminal && $projected->status->terminal()) {
                $this->projectState($projected, $result, $evidence);
            }

            return $projected;
        });
    }

    /** @param array<string,mixed> $data
     * @param  array<string,mixed>  $native
     * @return array<string,mixed>
     */
    private function nativeResult(Operation $operation, array $data, array $native): array
    {
        $decision = ValidatedData::object($native['decision'] ?? []);
        $observation = ValidatedData::object($native['observation'] ?? []);
        $result = array_intersect_key($decision, array_flip(['manager_version', 'installed_version', 'native_error_code', 'error_code', 'outcome']));
        // The newest reading of the package wins, absence included: the decoded end state, else a decision that read the inventory. Only
        // a decision that read nothing repeats the version the run was admitted with.
        $read = self::reads($observation) ? $observation : self::decisionReading($operation, $decision);
        $result['installed_coordinate'] = $read !== null ? ($read['installed_coordinate'] ?? $read['installed_version'] ?? null)
            : ($decision['installed_coordinate'] ?? $decision['installed_version'] ?? $operation->input['installed_version'] ?? null);
        $result['installed_version'] = ($read ?? $decision)['installed_version'] ?? $result['installed_coordinate'];
        if (($operation->input['manager'] ?? null) === ToolManagerName::BrewCask->value && is_string($result['installed_version'])) {
            $result['installed_version'] = explode(',', $result['installed_version'], 2)[0];
        }
        if (($data['status'] ?? null) === 'succeeded') {
            if (NativeRunResult::stepStatus($native, 'Assert package state') !== 'succeeded' || ($decision['ready'] ?? false) !== true) {
                throw new ResourceOperationException('operation.result_invalid', 'The native Tool end check is required.', 422);
            }
            if ($operation->kind === 'tool.remove') {
                $result['installed_version'] = null;
                $result['installed_coordinate'] = null;
                $result['outcome'] = 'applied';
            } elseif (array_key_exists('installed_version', $observation)) {
                // The raw coordinates tell a new cask build from the same one.
                $after = $observation['installed_coordinate'] ?? $observation['installed_version'];
                $before = $decision['installed_coordinate'] ?? $decision['installed_version'] ?? null;
                $result['outcome'] = $after === $before ? 'unchanged' : 'applied';
            }
        } elseif (($data['status'] ?? null) === 'failed') {
            $code = $decision['error_code'] ?? 'tool.version_probe_failed';
            if (NativeRunResult::stepStatus($native, 'Set up the Tool Manager') === 'failed') {
                $code = 'tool.manager_provision_failed';
            } else {
                foreach (['Pin repository packages', 'Prefer the verified package', 'Reconcile remaining repository pins'] as $step) {
                    if (NativeRunResult::stepStatus($native, $step) === 'failed') {
                        $code = 'tool.repository_failed';
                        break;
                    }
                }
                foreach (['Apply the package', 'Remove the package'] as $step) {
                    if (NativeRunResult::stepStatus($native, $step) === 'failed') {
                        $code = $operation->kind.'_failed';
                        break;
                    }
                }
            }
            if (NativeRunResult::stepStatus($native, 'Refresh apt source') === 'failed') {
                $code = 'tool.repository_unavailable';
            }
            $result['error_code'] = $code;
            unset($result['outcome']);
        }

        return $result;
    }

    /**
     * What the run's own receipt proves, whatever its terminal status (docs/reference/tools.md#ownership):
     * - `acquired`: Orbit's own package write was verified present;
     * - `released`: the package was absent at the start of a removal, its removal was verified, or its removal step ran at all: one that
     *   succeeded, failed or was cut off may have taken the package even when its absence was never confirmed;
     * - `retained`: a removal's receipt shows it never ran its package removal, so the package it was admitted to own is untouched.
     * A receipt without steps proves nothing.
     *
     * @param  array<string, mixed>  $native
     *                                        - `observed`: the receipt reports the installed version, as the decision read it or the package state decoded it.
     * @return array{acquired: bool, released: bool, retained: bool, observed: bool}
     */
    private function evidence(Operation $operation, array $native): array
    {
        $decision = ValidatedData::object($native['decision'] ?? []);
        $observation = ValidatedData::object($native['observation'] ?? []);
        $decoded = NativeRunResult::stepStatus($native, 'Decode package state') === 'succeeded' && ($observation['compliant'] ?? false) === true;
        $removal = $operation->kind === 'tool.remove';
        $status = static fn (string $step): ?string => NativeRunResult::stepStatus($native, $step);

        // A package step that ran at all may have removed the package, a nonzero exit included (dpkg can remove it and still fail on
        // another package's trigger). A source-only cleanup's decision skips the package without reading it.
        $owned = $this->packageOwned($operation);
        // After a failed package step the run checks the package again: still present, it survived a refusal before any write (a held
        // dpkg lock); absent or unread, it may be gone.
        $ran = ! in_array($status('Remove the package'), [null, 'skipped'], true);
        $survived = $ran && NativeRunResult::stepStatus($native, 'Decode package state') === 'succeeded' && ($observation['present'] ?? false) === true;
        $released = $removal && ((($decision['ready'] ?? false) === true
            && (($owned && ($decision['remove'] ?? null) === false) || ($decoded && ($observation['absent'] ?? false) === true)))
            || ($ran && ! $survived));

        return [
            'acquired' => ! $removal && ($decision['mutate'] ?? false) === true && $decoded && ($observation['present'] ?? false) === true,
            'released' => $released,
            'retained' => $removal && ! $released && is_array($native['steps'] ?? null) && $native['steps'] !== [] && (! $ran || $survived),
            // A refused decision may only repeat the version it was admitted with, after an unreadable inventory: only a decision that read
            // the package, or the decoded state, observed it.
            'observed' => self::reads($observation) || self::decisionReading($operation, $decision) !== null,
        ];
    }

    /** @param array<string, mixed> $reading */
    private static function reads(array $reading): bool
    {
        return array_key_exists('installed_version', $reading) || array_key_exists('installed_coordinate', $reading);
    }

    /**
     * What the decision read of the package, absence included, or `null` when it read nothing. A ready decision read it; a refused one says
     * whether it did (`inventory_read`). The preceding release's refused decision, read after the upgrade, has no `inventory_read`, and
     * repeats the admitted version whenever the package was absent: PrecedingToolDecision reads it by that release's own decision code.
     *
     * @param  array<string, mixed>  $decision
     * @return array{installed_version: ?string, installed_coordinate: ?string}|null
     */
    private static function decisionReading(Operation $operation, array $decision): ?array
    {
        if (! self::reads($decision)) {
            return null;
        }
        $coordinate = ValidatedData::nullableString($decision['installed_coordinate'] ?? $decision['installed_version'] ?? null);
        if (($decision['ready'] ?? false) === true || ($decision['inventory_read'] ?? false) === true) {
            return ['installed_version' => ValidatedData::nullableString($decision['installed_version'] ?? $coordinate), 'installed_coordinate' => $coordinate];
        }
        // A decision that recorded no version field shows nothing of what it read.
        if (array_key_exists('inventory_read', $decision) || (! array_key_exists('installed_coordinate', $decision) && ! array_key_exists('installed_version', $decision))) {
            return null;
        }
        $reading = PrecedingToolDecision::reading($operation->kind, ValidatedData::string($operation->input['manager'] ?? ''), ValidatedData::string($operation->input['platform'] ?? ''),
            false, ValidatedData::nullableString($decision['error_code'] ?? null), $coordinate, ValidatedData::nullableString($operation->input['installed_version'] ?? null));

        return $reading === null ? null : ['installed_version' => $reading['version'] === null ? null : ValidatedData::nullableString($decision['installed_version'] ?? $reading['version']),
            'installed_coordinate' => $reading['version']];
    }

    /**
     * The reviewed Tool charts: install recorded its Tool as installing at admission; a failed manager setup drops it and
     * fails the manager; any other failure marks the Tool failed. A Tool write never changes its repository.
     *
     * @param  array<string, mixed>  $result
     * @param  array{acquired: bool, released: bool, retained: bool, observed: bool}  $evidence
     */
    private function projectState(Operation $operation, array $result, array $evidence, bool $late = false): void
    {
        $status = $operation->status;
        $verb = substr($operation->kind, 5);
        $input = $operation->input;
        $version = $result['manager_version'] ?? null;
        $record = ToolManagerRecord::query()->where('node_id', $operation->node_id)->where('name', $input['manager'])->first();
        // The manager's state, like each Tool field, changes only if no newer receipt changed it (ToolManagerRecord::receive).
        if (is_string($version)) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $record->receive($operation->id, ['status' => LifecycleStatus::Active, 'installed_version' => $version, 'failed_step' => null, 'error_code' => null]);
        }
        $managerFailed = $verb === 'install' && $status === OperationStatus::Failed
            && in_array($operation->error_code, ['tool.manager_provision_failed', 'tool.manager_unavailable'], true);
        if ($managerFailed) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $native = ValidatedData::string($result['native_error_code'] ?? '');
            $absent = str_ends_with($native, '_scope_absent');
            $conflict = str_ends_with($native, '_scope_conflict');
            $record->receive($operation->id, ['status' => LifecycleStatus::Failed,
                'failed_step' => $absent ? 'manager-absent' : ($conflict ? 'manager-conflict' : 'materialize'),
                'error_code' => $absent ? 'node.tool_manager_absent' : ($conflict ? 'node.tool_manager_conflict' : 'node.tool_manager_materialization_failed')]);
        }
        $toolId = $input['tool_id'] ?? null;
        $tool = is_int($toolId) ? Tool::query()->find($toolId) : null;
        if ($status !== OperationStatus::Succeeded) {
            // An installing record that never became a Tool goes: a failed setup, a package Orbit did not install, a cancel. The same
            // receipt counts whether the run failed, was cancelled or timed out: an acquisition or a confirmed absence it recorded is kept,
            // and so is the row it applies to. Each field changes only if no newer receipt changed it (Tool::receive).
            $id = $operation->id;
            // A removal that never reached its package gives back what a timeout set aside (docs/reference/tools.md#ownership).
            if ($evidence['retained']) {
                $tool?->resume($id);
            }
            $evidenced = $evidence['acquired'] || $evidence['released'];
            if ($tool?->status === ToolStatus::Installing && $tool->currentFor($id) && ! $evidenced && (($managerFailed && $tool->apt_repository_id === null
                && $tool->desired_version === null && $tool->version_constraint === null) || $status === OperationStatus::Cancelled
                || $operation->error_code === 'tool.package_already_installed')) {
                $tool->delete();

                return;
            }
            if ($status === OperationStatus::Cancelled && ! $evidenced) {
                return;
            }
            // A verified write stays Orbit's whatever failed after it; a removal that may have taken the package ends Orbit's claim.
            $tool?->receive($id, ['status' => ToolStatus::Failed, 'failed_operation' => ToolOperation::from($verb), 'error_code' => $operation->error_code],
                owned: $evidence['acquired'] ? true : ($evidence['released'] ? false : null),
                version: $evidence['released'] ? null : ValidatedData::nullableString($result['installed_coordinate'] ?? null),
                observed: $evidence['released'] || ($evidence['observed'] && $operation->error_code !== 'tool.package_already_installed'));

            return;
        }
        if ($verb === 'remove') {
            // The repository stays: it has its own lifecycle (tool:apt-repository-remove).
            if ($tool?->currentFor($operation->id) === true) {
                $tool->delete();
            }

            return;
        }
        // A first install's record is made here, with the repository it was admitted with; a late receipt never brings back a Tool
        // removed since. A receipt never changes the Tool's repository: only an admission sets it.
        if ($tool === null && $record !== null && ! $late) {
            $tool = $this->createTool($operation, $record);
        }
        // Only Orbit's own verified write acquires the package: a success that found it present and left it unchanged observes it, so a
        // preceding-release receipt read after the upgrade never acquires a package Orbit never wrote (docs/reference/tools.md#ownership).
        $tool?->receive($operation->id, ['status' => ToolStatus::Installed, 'failed_operation' => null, 'error_code' => null],
            owned: $evidence['acquired'] ? true : null, version: ValidatedData::nullableString($result['installed_coordinate'] ?? null), observed: true);
    }

    /**
     * A timed-out run may still be changing the Node, also when its start reply never bound it. A removal may already have removed its
     * package, so until a receipt shows otherwise its Tool waits for `tool:remove` and no retry may remove whatever package is there
     * now. A write grants nothing.
     */
    public function timedOut(Operation $operation): void
    {
        $tool = is_int($operation->input['tool_id'] ?? null) ? Tool::query()->find($operation->input['tool_id']) : null;
        if ($operation->kind === 'tool.remove' && $tool !== null && ! self::neverStarted($operation)) {
            $tool->suspend($operation->id, ['status' => ToolStatus::Failed, 'failed_operation' => ToolOperation::Remove, 'error_code' => 'operation.timed_out']);
        }
    }

    /**
     * The Operation never ran: it was cancelled before its run, or Swamp has no run for its lost start. A removal a timeout set aside gets
     * back the Tool as it was, a preceding-release admission's included.
     */
    public function neverRan(Operation $operation): void
    {
        $tool = is_int($operation->input['tool_id'] ?? null) ? Tool::query()->find($operation->input['tool_id']) : null;
        $tool?->resume($operation->id);
    }

    /** No start was sent (StartSwampRun::startMayHaveBeenSent): never begun, or its latest begin answered by a deferral. */
    private static function neverStarted(Operation $operation): bool
    {
        return ! StartSwampRun::startMayHaveBeenSent($operation);
    }

    /**
     * The receipt of a run that ended after its timeout counts for the Tool as it would have on time; the Operation stays timed out.
     * An unreadable receipt counts as a failure that proves nothing. Each field it shows changes only if no newer receipt changed it
     * (Tool::receive). A Tool already removed stays removed.
     */
    public function late(Operation $operation, string $status, array $result, array $run): void
    {
        try {
            $native = $this->nativeResult($operation, ['status' => $status], $result);
        } catch (ResourceOperationException) {
            [$status, $result, $native] = ['failed', [], []];
        }
        $evidence = $this->evidence($operation, $result);
        $ended = OperationStatus::from($status);
        // A failure the receipt names is the Tool's error; otherwise it stays the timeout the Operation recorded.
        $readable = is_array($result['steps'] ?? null) && $result['steps'] !== [];
        $view = (clone $operation)->forceFill(['status' => $ended, 'error_code' => match (true) {
            $ended === OperationStatus::Succeeded => null,
            $ended === OperationStatus::Failed && $readable => $native['error_code'] ?? $operation->error_code,
            default => $operation->error_code,
        }]);
        $this->projectState($view, $native, $evidence, late: true);
    }

    private function createTool(Operation $operation, ToolManagerRecord $record): Tool
    {
        return Tool::query()->create([
            'node_id' => $operation->node_id, 'tool_manager_id' => $record->id,
            'package' => $operation->input['package'], 'version_constraint' => $operation->input['version_constraint'],
            'desired_version' => $operation->input['desired_version'] ?? null,
            'apt_repository_id' => is_int($admitted = $operation->input['repository_id'] ?? null) && AptRepository::query()->whereKey($admitted)->exists() ? $admitted : null,
            'status' => ToolStatus::Installing,
        ]);
    }
}
