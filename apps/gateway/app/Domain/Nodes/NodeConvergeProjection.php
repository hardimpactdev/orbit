<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationLateResult;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Proof\ProofCopyHosts;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptSourceTrust;
use App\Domain\Tools\ToolConvergeObservations;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Bounded results only. Converge never projects assignments or Instances. */
final readonly class NodeConvergeProjection implements DirectOperationLateResult, DirectOperationProjection
{
    public function __construct(private ToolConvergeObservations $toolObservations) {}

    public function input(Operation $operation): array
    {
        $snapshot = ValidatedData::object($operation->input['_converge'] ?? []);

        return ['intent' => $snapshot['intent'] ?? [], 'execution_secrets' => $snapshot['execution_secrets'] ?? []];
    }

    /** A report that arrived after the timeout still records each Tool it verified installed (ToolConvergeObservations::late). */
    public function late(Operation $operation, string $status, array $result): void
    {
        $intent = ValidatedData::object(ValidatedData::object($operation->input['_converge'] ?? [])['intent'] ?? []);
        $admitted = array_column(array_map(ValidatedData::object(...), is_array($intent['tools'] ?? null) ? $intent['tools'] : []), null, 'id');
        $phase = match (data_get($result, 'phases.4.status')) {
            'succeeded' => 'completed', 'unchanged', 'skipped' => 'skipped', default => 'partial',
        };
        $this->toolObservations->late($operation, $admitted, $result['tools'] ?? [], $phase, ValidatedData::stringList($intent['orphan_sources'] ?? []));
    }

    /** A convergence that never ran wrote nothing: each source gets back the trust it was admitted with. */
    public function neverRan(Operation $operation): void
    {
        AptSourceTrust::restoreAdmitted(ValidatedData::integer($operation->node_id), ValidatedData::object($operation->input['repository_admitted'] ?? []), $operation->id);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $snapshot = ValidatedData::object($operation->input['_converge'] ?? []);
        $intent = ValidatedData::object($snapshot['intent'] ?? []);
        $admitted = array_column(array_map(ValidatedData::object(...), is_array($intent['tools'] ?? null) ? $intent['tools'] : []), null, 'id');
        try {
            $result = NodeConvergeResult::parse($data['result'] ?? null);
            $phases = array_map(ValidatedData::object(...), is_array($result['phases'] ?? null) ? $result['phases'] : []);
            $tools = $this->toolObservations->validate($result['tools'] ?? [], $admitted, $phases[4]['status'] === 'succeeded');
            if (($data['status'] ?? null) !== 'succeeded' && $result['outcome'] === 'succeeded') {
                throw new ResourceOperationException('operation.result_invalid', 'An unsuccessful native run cannot report success.', 422);
            }
        } catch (ResourceOperationException $error) {
            self::recordCiRunner($operation, $intent, false);

            return app(DirectOperation::class)->project($operation, [...$data, 'status' => 'failed', 'error_code' => $error->errorCode], ['error_code' => $error->errorCode]);
        }
        $data = [...$data, 'status' => $result['outcome'], 'error_code' => $result['error_code'] ?? null];

        return DB::transaction(function () use ($operation, $data, $result, $tools, $admitted, $intent, $snapshot): Operation {
            $locked = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked;
            }
            $this->toolObservations->record($locked, $admitted, $tools, data_get($result, 'phases.4.status') === 'succeeded');
            if (data_get($result, 'phases.4.status') === 'succeeded') {
                // The Tools phase removed the files of the sources no Tool named at admission.
                AptSourceTrust::forgetOrphans(ValidatedData::integer($operation->node_id), ValidatedData::stringList($intent['orphan_sources'] ?? []));
            }
            if (in_array(data_get($result, 'phases.4.status'), ['unchanged', 'skipped'], true)) {
                // The Tools phase did not run, so it wrote no source: the trust its admission suspended holds again.
                AptSourceTrust::restoreAdmitted(ValidatedData::integer($operation->node_id), ValidatedData::object($operation->input['repository_admitted'] ?? []), $operation->id);
            }

            $phases = $result['phases'];
            $rolesPassed = is_array($phases) && NodeConvergeResult::passed($phases[3]);
            self::recordCiRunner($operation, $intent, $rolesPassed);
            if ($result['outcome'] === 'succeeded') {
                // A network intent always names the hub; the network play writes it as a host of its own.
                $digests = array_key_exists('held_digests', $snapshot)
                    ? app(NodeConvergePhaseDigest::class)->held(array_map(ValidatedData::string(...), ValidatedData::object($snapshot['held_digests'])),
                        array_map(ValidatedData::object(...), is_array($phases) ? $phases : []), ($intent['network'] ?? null) !== null)
                    : ValidatedData::object($result['digests']);
                $restartDeferred = ($result['t3_restart_deferred'] ?? []) !== [];
                if ($restartDeferred) {
                    unset($digests['roles']);
                }
                if (in_array('failed', array_column($tools, 'status'), true)) {
                    unset($digests['tools']);
                }
                app(NodeConvergePhaseDigest::class)->record(ValidatedData::integer($operation->node_id), $digests, full: true);
                if (! $restartDeferred && isset($snapshot['release_inputs'])) {
                    $node = Node::query()->whereKey($operation->node_id)->lockForUpdate()->firstOrFail();
                    $inputs = ValidatedData::object($snapshot['release_inputs']);
                    $recorded = $node->release_inputs ?? [];
                    $roles = array_replace((array) ($recorded['roles'] ?? []), (array) $inputs['roles']);
                    if (isset($inputs['host'])) {
                        $recorded['host'] = $inputs['host'];
                    }
                    $node->forceFill(['release_inputs' => [...$recorded, 'roles' => $roles]])->save();
                }
            }
            if ($rolesPassed) {
                foreach (is_array($intent['roles'] ?? null) ? $intent['roles'] : [] as $role) {
                    $role = ValidatedData::object($role);
                    app(GatewayPrivateDnsState::class)->record(ValidatedData::integer($operation->node_id), ValidatedData::object($role['runtime'] ?? []));
                }
            }

            return app(DirectOperation::class)->project($locked, $data, $result);
        });
    }

    /**
     * The roles phase of a converge with `ci-runner` writes or removes the seed listener's trust, writes the hold script and prepares the
     * Instance container branch, so its end records the listener by the one rule of {@see ProofSeeds::recordListener()}, and the script and
     * nested hosting by that of {@see ProofCopyHosts::recordConverge()}.
     * It never opens the listener. A CI Node that is behind the default's seed catches up after any passed converge, so a passed roles
     * phase admits a seed copy for it, as a `ci-runner` role converge does: a Node whose copy failed is not left to wait for the next
     * seed (docs/reference/development-containers.md#the-seed-on-each-ci-node). The admission takes only a Node whose record still names
     * the sender.
     *
     * @param  array<string, mixed>  $intent
     */
    private static function recordCiRunner(Operation $operation, array $intent, bool $rolesPassed): void
    {
        foreach (is_array($intent['roles'] ?? null) ? $intent['roles'] : [] as $role) {
            $role = ValidatedData::object($role);
            if (($role['role'] ?? null) === RoleName::CiRunner->value) {
                $converged = ValidatedData::integer($operation->node_id);
                ProofSeeds::recordListener($converged, ValidatedData::object($role['runtime'] ?? []), $rolesPassed, opens: false);
                ProofCopyHosts::recordConverge($converged, ValidatedData::object($role['runtime'] ?? []), $rolesPassed, sets: false);
                if ($rolesPassed) {
                    DB::afterCommit(static fn (): ?Operation => app(ProofSeeds::class)->admit('node_converge', node: $converged));
                }
            }
        }
    }
}
