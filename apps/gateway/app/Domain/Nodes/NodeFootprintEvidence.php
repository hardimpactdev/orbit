<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\NodeFootprintPlan;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\RouteRemovalResidue;
use App\Support\ValidatedData;

/** Derives footprint results from transport receipts and stamped child Operations, never posted step claims. */
final readonly class NodeFootprintEvidence
{
    public function __construct(private OperationChildren $children) {}

    /** @param iterable<Operation> $children
     * @return array{result: array<string, mixed>, recorded: array<string, string>, error_code: ?string}
     */
    public function derive(Operation $operation, iterable $children, bool $allowIncomplete = false): array
    {
        $snapshot = ValidatedData::object($operation->input['_footprint'] ?? []);
        $prior = ValidatedData::object($snapshot['recorded'] ?? []);
        $recorded = [];
        foreach ($prior as $name => $digest) {
            $recorded[$name] = ValidatedData::string($digest);
        }
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $applied = [];
        $artifacts = [];
        $skipped = [];
        $error = null;
        $firstCaddyFailure = false;
        $childByKey = [];
        foreach ($children as $child) {
            $this->children->verify($operation, $child);
            if ($child->getAttribute('parent_operation_id') !== $operation->id) {
                throw $this->unproven();
            }
            $childByKey[ValidatedData::string($child->getAttribute('child_key'))] = $child;
        }
        $plan = $snapshot['artifacts'] ?? [];
        if (! is_array($plan) || ! array_is_list($plan)) {
            throw $this->unproven();
        }
        foreach ($plan as $raw) {
            $artifact = ValidatedData::object($raw);
            $name = ValidatedData::string($artifact['name']);
            $digest = ValidatedData::string($artifact['digest']);
            if (($artifact['required'] ?? null) !== true) {
                $artifacts[] = ['name' => $name, 'status' => 'unchanged', 'reason' => null];
                $applied[$name] = $digest;

                continue;
            }
            $specs = ValidatedData::object($snapshot['children'] ?? []);
            $spec = $specs[$name] ?? null;
            if (is_array($spec)) {
                if ($name === 'private-dns') {
                    $listener = $runs['footprint-private-dns-listener'] ?? null;
                    if (! is_array($listener) || ($listener['transport'] ?? null) !== 'agent'
                        || ($listener['name'] ?? null) !== 'node.install-private-dns-listener'
                        || ! is_array($listener['result'] ?? null)) {
                        if ($allowIncomplete) {
                            return $this->result($artifacts, $skipped, [...$recorded, ...$applied], null);
                        }
                        throw $this->unproven();
                    }
                    if (isset($listener['result']['error_code'])) {
                        $retained = [...$recorded, ...$applied];
                        unset($retained[$name]);

                        return $this->result($artifacts, $skipped, $retained, ValidatedData::string($listener['result']['error_code']));
                    }
                    if (($listener['result']['installed'] ?? null) !== true) {
                        throw $this->unproven();
                    }
                }
                $child = $childByKey['footprint-'.$name] ?? null;
                if ($child === null || ! $child->status->terminal()) {
                    if ($allowIncomplete) {
                        return $this->result($artifacts, $skipped, [...$recorded, ...$applied], null);
                    }
                    throw new ResourceOperationException('operation.children_pending', 'The footprint child has not settled.', 409);
                }
                $intent = ValidatedData::object(ValidatedData::object($child->child_admission ?? [])['intent'] ?? []);
                if ($child->kind !== ($spec['kind'] ?? null) || $child->node_id !== ($spec['node_id'] ?? null)
                    || ($intent['input'] ?? null) !== ($spec['input'] ?? null)) {
                    throw $this->unproven();
                }
                if ($child->status !== OperationStatus::Succeeded) {
                    $stage = $child->result['stage'] ?? null;
                    $caddySkip = $name === 'caddy' && $child->status === OperationStatus::Failed && in_array($stage, ['render', 'validate'], true);
                    $residueSkip = $name === 'route-residue' && $child->status === OperationStatus::Failed;
                    if ($caddySkip || $residueSkip) {
                        $reason = $residueSkip ? 'route_residue_cleanup_failed' : ($stage === 'render' ? 'caddy_render_refused' : 'caddy_validate_refused');
                        $skipped[] = ['child_id' => $child->id, 'key' => ValidatedData::string($child->getAttribute('child_key')), 'kind' => $child->kind, 'reason' => $reason];
                        $artifacts[] = ['name' => $name, 'status' => 'skipped', 'reason' => $reason];
                        $applied[$name] = $digest;
                        if ($caddySkip && $stage === 'validate' && ($operation->input['first_visit'] ?? false) === true && ! ($artifact['repair'] ?? false)) {
                            $firstCaddyFailure = true;
                        }

                        continue;
                    }
                    $error = $child->status === OperationStatus::Cancelled ? 'operation.cancelled' : ($child->error_code ?? 'node.footprint_failed');
                    if ($child->status === OperationStatus::Failed) {
                        $skipped[] = ['child_id' => $child->id, 'key' => ValidatedData::string($child->getAttribute('child_key')), 'kind' => $child->kind, 'reason' => $error];
                        $artifacts[] = ['name' => $name, 'status' => 'skipped', 'reason' => $error];
                    }
                }
                $changed = $child->kind === 'route.caddy_converge' && ($child->result['result'] ?? null) === 'unchanged'
                    ? false : ($child->result['changed'] ?? true);
            } else {
                [$transport, $command] = match ($name) {
                    'agent' => ['agent', 'agent.configure'],
                    'caddy-package' => ['agent', 'caddy.install-package'],
                    'peer-dns' => ['agent', 'wireguard.repair-peer-dns'],
                    'tmpfiles' => ['agent', 'node.publish-tmpfiles'],
                    default => throw $this->unproven(),
                };
                if ($name === 'agent') {
                    $install = $runs['footprint-agent-install'] ?? null;
                    if (is_array($install) && ($install['transport'] ?? null) === 'node-agent' && ($install['name'] ?? null) === 'agent.install'
                        && is_array($install['result'] ?? null) && isset($install['result']['error_code'])) {
                        $retained = [...$recorded, ...$applied];
                        unset($retained[$name]);

                        return $this->result($artifacts, $skipped, $retained, ValidatedData::string($install['result']['error_code']));
                    }
                }
                $run = $runs['footprint-'.$name] ?? null;
                if (! is_array($run) || ($run['transport'] ?? null) !== $transport || ($run['name'] ?? null) !== $command || ! is_array($run['result'] ?? null)) {
                    if ($allowIncomplete) {
                        return $this->result($artifacts, $skipped, [...$recorded, ...$applied], null);
                    }
                    throw $this->unproven();
                }
                $error = isset($run['result']['error_code']) ? ValidatedData::string($run['result']['error_code']) : null;
                $changed = $run['result']['changed'] ?? true;
                if ($name === 'agent' && $error === null) {
                    $install = $runs['footprint-agent-install'] ?? null;
                    if (! is_array($install) || ($install['transport'] ?? null) !== 'node-agent' || ($install['name'] ?? null) !== 'agent.install'
                        || ! is_array($install['result'] ?? null) || isset($install['result']['error_code']) || ($install['result']['installed'] ?? null) !== true) {
                        throw $this->unproven();
                    }
                }
            }
            if ($error !== null) {
                $retained = [...$recorded, ...$applied];
                unset($retained[$name]);

                return $this->result($artifacts, $skipped, $retained, $error);
            }
            $artifacts[] = ['name' => $name, 'status' => $changed === false ? 'unchanged' : 'applied', 'reason' => null];
            if ($name !== 'route-residue' || RouteRemovalResidue::query()->where('node_id', $operation->node_id)->exists()) {
                $applied[$name] = $digest;
            }
        }

        return $this->result($artifacts, $skipped, $applied, $firstCaddyFailure ? 'node.footprint_caddy_failed' : null);
    }

    /** @param list<array<string, mixed>> $artifacts
     * @param  list<array<string, mixed>>  $skipped
     * @param  array<string, string>  $recorded
     * @return array{result: array<string, mixed>, recorded: array<string, string>, error_code: ?string}
     */
    private function result(array $artifacts, array $skipped, array $recorded, ?string $error): array
    {
        usort($artifacts, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        $result = ['footprint_proven' => true, 'artifacts' => $artifacts, 'skipped' => $skipped,
            'digest' => NodeFootprintPlan::of($recorded)->digest(), 'changed' => array_any($artifacts, static fn (array $item): bool => $item['status'] === 'applied')];
        if ($error !== null) {
            $result['error_code'] = $error;
        }

        return ['result' => $result, 'recorded' => $recorded, 'error_code' => $error];
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'The footprint has no matching target receipt.', 409);
    }
}
