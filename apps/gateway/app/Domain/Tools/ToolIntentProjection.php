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
            'node_id', 'manager', 'package', 'user', 'platform', 'architecture', 'version_constraint', 'desired_version', 'tool_id', 'tool_status', 'installed_version', 'setup', 'repository', 'remove_repository', 'repository_packages', 'repository_given', 'manager_ready', 'package_owned', 'repository_origins',
        ]))], 'ssh' => $operation->input['ssh']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $public = ValidatedData::object($data['result'] ?? []);
        $result = $this->nativeResult($operation, $data, $public);
        $evidence = $this->evidence($operation, $public);
        $data['error_code'] = $result['error_code'] ?? ($data['error_code'] ?? null);
        $result = array_intersect_key($result, array_flip([
            'native_error_code', 'manager_version', 'installed_version', 'installed_coordinate', 'outcome', 'block', 'error_code', 'repository_removed',
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
        if (isset($result['repository_removed']) && ! is_bool($result['repository_removed'])) {
            throw new ResourceOperationException('operation.result_invalid', 'The Tool repository result is invalid.', 422);
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
        $result['installed_coordinate'] = $observation['installed_coordinate'] ?? $observation['installed_version']
            ?? $decision['installed_coordinate'] ?? $decision['installed_version'] ?? $operation->input['installed_version'] ?? null;
        $result['installed_version'] = $observation['installed_version'] ?? $decision['installed_version'] ?? $result['installed_coordinate'];
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
                if (($operation->input['remove_repository'] ?? false) === true) {
                    // Both steps allow failure; only both together confirm the source and its preferences are gone.
                    $result['repository_removed'] = NativeRunResult::stepStatus($native, 'Remove the apt source') === 'succeeded'
                        && NativeRunResult::stepStatus($native, 'Remove repository pins') === 'succeeded';
                }
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
            } elseif (NativeRunResult::stepStatus($native, 'Assert the key fingerprint') === 'failed') {
                $code = 'tool.repository_key_mismatch';
            } else {
                foreach (['Prepare apt keyrings', 'Fetch the signing key', 'Read the key fingerprint', 'Dearmor the signing key', 'Publish the signing key', 'Pin repository packages', 'Write the apt source', 'Prefer the verified package', 'Reconcile remaining repository pins'] as $step) {
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
     * - `released`: the package was absent at the start of a removal, or its removal was verified;
     * - `retained`: a removal's receipt shows it never ran its package removal, so the package it was admitted to own is untouched;
     * - `untouched`: the run wrote nothing to the source that its owned members do not already have. Each source-writing step is
     *   recorded as skipped, or an earlier step failed and none of them ran.
     * A receipt without steps proves nothing.
     *
     * @param  array<string, mixed>  $native
     * @return array{acquired: bool, released: bool, retained: bool, untouched: bool}
     */
    private function evidence(Operation $operation, array $native): array
    {
        $decision = ValidatedData::object($native['decision'] ?? []);
        $observation = ValidatedData::object($native['observation'] ?? []);
        $decoded = NativeRunResult::stepStatus($native, 'Decode package state') === 'succeeded' && ($observation['compliant'] ?? false) === true;
        $removal = $operation->kind === 'tool.remove';
        [$before, $writes] = $removal
            ? [['Read manager version', 'Read installed package', 'Plan the removal', 'Choose package action', 'Remove the package', 'Check apt absence'],
                ['Remove the apt source', 'Remove repository pins', 'Reconcile remaining repository pins']]
            : [['Prepare package commands', 'Fetch the signing key', 'Read the key fingerprint', 'Assert the key fingerprint', 'Dearmor the signing key',
                'Prepare apt keyrings', 'Set up the Tool Manager'], ['Publish the signing key', 'Write the apt source', 'Prefer the verified package']];
        $status = static fn (string $step): ?string => NativeRunResult::stepStatus($native, $step);
        $skipped = array_filter($writes, static fn (string $step): bool => $status($step) === 'skipped') === $writes;
        $stopped = array_filter($before, static fn (string $step): bool => $status($step) === 'failed') !== []
            && array_filter($writes, static fn (string $step): bool => ! in_array($status($step), [null, 'skipped'], true)) === [];

        $released = $removal && ($decision['ready'] ?? false) === true
            && (($decision['remove'] ?? null) === false || ($decoded && ($observation['absent'] ?? false) === true));

        return [
            'acquired' => ! $removal && ($decision['mutate'] ?? false) === true && $decoded && ($observation['present'] ?? false) === true,
            'released' => $released,
            'retained' => $removal && ! $released && is_array($native['steps'] ?? null) && $native['steps'] !== []
                && in_array($status('Remove the package'), [null, 'skipped'], true),
            'untouched' => is_array($operation->input['repository'] ?? null) && ($skipped || $stopped),
        ];
    }

    /**
     * The reviewed Tool charts: install recorded its Tool as installing at admission; a failed manager setup drops it and
     * fails the manager; any other failure marks the Tool failed. Repository definitions change only on success (R2).
     *
     * @param  array<string, mixed>  $result
     * @param  array{acquired: bool, released: bool, retained: bool, untouched: bool}  $evidence
     */
    private function projectState(Operation $operation, array $result, array $evidence): void
    {
        $status = $operation->status;
        $verb = substr($operation->kind, 5);
        $input = $operation->input;
        $version = $result['manager_version'] ?? null;
        $record = ToolManagerRecord::query()->where('node_id', $operation->node_id)->where('name', $input['manager'])->first();
        if (is_string($version)) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $record->update(['status' => LifecycleStatus::Active, 'installed_version' => $version, 'failed_step' => null, 'error_code' => null]);
        }
        $managerFailed = $verb === 'install' && $status === OperationStatus::Failed
            && in_array($operation->error_code, ['tool.manager_provision_failed', 'tool.manager_unavailable'], true);
        if ($managerFailed) {
            $record ??= ToolManagerRecord::query()->create(['node_id' => $operation->node_id, 'name' => $input['manager'], 'status' => LifecycleStatus::Provisioning]);
            $native = ValidatedData::string($result['native_error_code'] ?? '');
            $absent = str_ends_with($native, '_scope_absent');
            $conflict = str_ends_with($native, '_scope_conflict');
            $record->update(['status' => LifecycleStatus::Failed,
                'failed_step' => $absent ? 'manager-absent' : ($conflict ? 'manager-conflict' : 'materialize'),
                'error_code' => $absent ? 'node.tool_manager_absent' : ($conflict ? 'node.tool_manager_conflict' : 'node.tool_manager_materialization_failed')]);
        }
        $toolId = $input['tool_id'] ?? null;
        $tool = is_int($toolId) ? Tool::query()->find($toolId) : null;
        if ($status !== OperationStatus::Succeeded) {
            // An installing record that never became a Tool goes: a failed setup, a package Orbit did not install, a cancel. One whose
            // run may already have written its apt source, key or pins stays as a failed Tool instead, so its removal can clean them.
            // The same receipt counts whether the run failed, was cancelled or timed out: an acquisition or a confirmed absence it
            // recorded is kept, and so is the row it applies to.
            $evidenced = $evidence['acquired'] || $evidence['released'];
            $published = $tool?->status === ToolStatus::Installing && is_array($input['repository'] ?? null)
                && ($status !== OperationStatus::Cancelled || isset($operation->state['swamp_run_id']));
            if ($tool?->status === ToolStatus::Installing && ! $published && ! $evidenced && (($managerFailed && $tool->desired_repository === null
                && $tool->desired_version === null && $tool->version_constraint === null) || $status === OperationStatus::Cancelled
                || $operation->error_code === 'tool.package_already_installed')) {
                $tool->delete();

                return;
            }
            if ($status === OperationStatus::Cancelled && ! $published && ! $evidenced) {
                return;
            }
            if ($evidence['untouched']) {
                $this->preserveTrust($operation);
            }
            // A verified write stays Orbit's whatever failed after it; a confirmed absence ends Orbit's claim on the package. A removal
            // that never reached its package keeps the claim it was admitted with, even after a timeout set it aside.
            $owned = $evidence['acquired'] || ($tool !== null && ! $evidence['released']
                && ($tool->owned || ($evidence['retained'] && ($input['package_owned'] ?? false) === true)));
            $tool?->update(['status' => ToolStatus::Failed, 'installed_version' => $operation->error_code === 'tool.package_already_installed'
                ? $tool->installed_version : ($evidence['released'] ? null : ($result['installed_coordinate'] ?? $tool->installed_version)),
                'failed_operation' => ToolOperation::from($verb), 'error_code' => $operation->error_code, 'owned' => $owned]);

            return;
        }
        if ($verb === 'remove') {
            // The last member's cleanup of its apt source is best-effort, so an unconfirmed one keeps the Tool as its retry handle; the
            // package itself is gone or was never Orbit's.
            if (($input['remove_repository'] ?? false) === true && ($result['repository_removed'] ?? false) !== true) {
                $tool?->update(['status' => ToolStatus::Failed, 'installed_version' => null, 'failed_operation' => ToolOperation::Remove,
                    'error_code' => 'tool.repository_failed', 'owned' => false]);

                return;
            }
            $tool?->delete();
            if (($input['remove_repository'] ?? false) !== true) {
                // The surviving members' preferences were rewritten from the verified record the removal was admitted with.
                $this->preserveTrust($operation);
            }
            if (($result['repository_removed'] ?? false) === true && is_int($input['repository_id'] ?? null)) {
                AptRepository::query()->whereKey($input['repository_id'])->delete();
            }

            return;
        }
        $repositoryId = $this->repository($operation);
        if ($tool === null && $record !== null) {
            $tool = $this->createTool($operation, $record);
        }
        // A successful install refused any package that was present without Orbit, so the package is now Orbit's (docs/reference/tools.md#remove-a-tool).
        $tool?->update(['apt_repository_id' => $repositoryId, 'status' => ToolStatus::Installed, 'owned' => true, 'installed_version' => $result['installed_coordinate'] ?? null, 'failed_operation' => null, 'error_code' => null]);
    }

    /**
     * A run that wrote nothing new to its source leaves the trust it was admitted with: a record verified at admission is verified
     * again, and one that was not, or did not exist, stays unproven (docs/reference/tools.md#source-trust).
     */
    private function preserveTrust(Operation $operation): void
    {
        $input = $operation->input;
        if (is_array($input['repository'] ?? null) && ($input['repository_verified'] ?? false) === true) {
            AptSourceTrust::prove(ValidatedData::integer($operation->node_id), ValidatedData::object($input['repository']));
        }
    }

    /**
     * A timed-out run may still be changing the Node. A removal may already have removed its package, so until a receipt shows
     * otherwise its Tool waits for `tool:remove` and no retry may remove whatever package is there now. A write grants nothing.
     */
    public function timedOut(Operation $operation): void
    {
        $tool = is_int($operation->input['tool_id'] ?? null) ? Tool::query()->find($operation->input['tool_id']) : null;
        if ($operation->kind === 'tool.remove' && $tool !== null && isset($operation->state['swamp_run_id'])) {
            $tool->update(['status' => ToolStatus::Failed, 'failed_operation' => ToolOperation::Remove, 'error_code' => 'operation.timed_out', 'owned' => false]);
        }
    }

    /**
     * The receipt of a run that ended after its timeout counts for the Tool as it would have on time; the Operation stays timed out.
     * An unreadable receipt counts as a failure that proves nothing.
     */
    public function late(Operation $operation, string $status, array $result): void
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
        $this->projectState($view, $native, $evidence);
        $tool = is_int($operation->input['tool_id'] ?? null) ? Tool::query()->find($operation->input['tool_id']) : null;
        if ($evidence['retained'] && ($operation->input['package_owned'] ?? false) === true) {
            $tool?->update(['owned' => true]);
        }
    }

    /** The admitted repository definition, stored once the run succeeded. */
    private function repository(Operation $operation): ?int
    {
        $definition = $operation->input['repository'] ?? null;
        if (! is_array($definition)) {
            return null;
        }

        return AptSourceTrust::record(ValidatedData::integer($operation->node_id), ValidatedData::object($definition))->id;
    }

    private function createTool(Operation $operation, ToolManagerRecord $record): Tool
    {
        return Tool::query()->create([
            'node_id' => $operation->node_id, 'tool_manager_id' => $record->id,
            'package' => $operation->input['package'], 'version_constraint' => $operation->input['version_constraint'],
            'desired_version' => $operation->input['desired_version'] ?? null,
            'desired_repository' => $operation->input['repository'] ?? null,
            'status' => ToolStatus::Installing,
        ]);
    }
}
