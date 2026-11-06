<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\ToolManagerRecord;
use App\Support\ValidatedData;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/** Record observed identity and completed native resource work from the one enrollment run. */
final readonly class NativeNodeEnrollmentProjection implements DirectOperationProjection, NativeOperationProjection
{
    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input['intent']);
        // A role without copies is stored as `[]`; the run reads each role's copies as a record, so an empty one goes as `{}`.
        if (is_array($intent['roles'] ?? null)) {
            $intent['roles'] = array_map(static fn (mixed $role): mixed => is_array($role) && ($role['execution_secrets'] ?? null) === []
                ? [...$role, 'execution_secrets' => (object) []] : $role, $intent['roles']);
        }

        return ['intent' => $intent, 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $proven = ($data['status'] ?? null) === 'succeeded' && NativeRunResult::stepStatus($raw, 'Check the Node') === 'succeeded';
            if (($data['status'] ?? null) === 'succeeded' && ! $proven) {
                throw new ResourceOperationException('operation.result_invalid', 'Enrollment has no successful native Node check.', 422);
            }
            $plan = ValidatedData::object($operation->input['_node_enrollment']);
            $summary = ValidatedData::object($raw['summary'] ?? []);
            $failed = ValidatedData::string($raw['failed_step'] ?? (is_array($summary['failed_steps'] ?? null) ? ($summary['failed_steps'][0] ?? 'enrollment') : 'enrollment'));
            $failedEntry = ValidatedData::object(ValidatedData::object($raw['steps'] ?? [])[$failed] ?? []);
            $error = $failedEntry['error_code'] ?? match (preg_replace('/-\d+$/', '', $failed)) {
                'Read the host key' => 'node.ssh_host_key_scan_failed',
                'Require approved host key' => 'node.ssh_host_key_mismatch',
                'Read the bootstrap account' => 'node.bootstrap_account_unavailable',
                'Verify managed access' => 'node.orbit_ssh_failed',
                default => 'node.enrollment_failed',
            };
            if (! $proven && ($data['status'] ?? null) === 'failed') {
                $data['error_code'] = $error;
            }
            $result = $proven ? ['id' => $operation->target_id, 'enrolled' => true]
                : ['id' => $operation->target_id, 'enrolled' => false, 'failed_step' => $failed];
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $node = Node::query()->whereKey($operation->target_id)->lockForUpdate()->firstOrFail();
            $observed = $this->observation($raw);
            if ($proven && $observed === null) {
                throw new ResourceOperationException('operation.result_invalid', 'Enrollment has no bound native Node identity.', 422);
            }
            if ($observed !== null && ($proven || $plan['existed'] === false)) {
                $node->fill(['architecture' => $observed['architecture'], 'ssh_host_key_type' => $observed['host_type'],
                    'ssh_host_key' => $observed['public_key'], 'ssh_host_fingerprint' => $observed['host_key_fingerprint']]);
            }
            if ($proven) {
                $node->fill(ValidatedData::object($plan['candidate']));
                $node->architecture = ValidatedData::string($observed['architecture']);
                if ($plan['agent_secret_hash'] !== null) {
                    $node->agent_secret_hash = ValidatedData::string($plan['agent_secret_hash']);
                    $key = $this->stdout($raw, 'Read WireGuard public key-0');
                    if (! is_string($key) || preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', trim($key)) !== 1) {
                        throw new ResourceOperationException('operation.result_invalid', 'Enrollment has no native WireGuard public key.', 422);
                    }
                    $node->wireguard_public_key = trim($key);
                    if (ValidatedData::object(ValidatedData::object($operation->input['intent'])['network'])['is_hub'] === true) {
                        app(Filesystem::class)->replace(rtrim(Config::string('orbit.home'), '/').'/wireguard/public.key', trim($key)."\n", 0600);
                    }
                }
                if ($plan['settings_provided'] === true) {
                    $node->settings = $plan['settings'] === null ? null : ValidatedData::object($plan['settings']);
                }
                $node->fill(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
            } elseif ($plan['existed'] === false) {
                $node->fill(['status' => LifecycleStatus::Failed, 'failed_step' => $failed,
                    'error_code' => $data['error_code'] ?? 'node.enrollment_failed']);
            }
            $node->save();
            if (NativeRunResult::stepStatus($raw, 'Set up the machine') === 'succeeded') {
                ToolManagerRecord::query()->updateOrCreate(['node_id' => $node->id, 'name' => ToolManagerName::Apt->value], ['status' => LifecycleStatus::Active]);
            }
            foreach (RouteExecutionProof::objects($plan['role_snapshots']) as $index => $snapshot) {
                $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $node->id)->firstOrFail();
                $status = NativeRunResult::stepStatus($raw, 'Converge each role-'.$index);
                if ($status === 'succeeded') {
                    $assignment->markConvergenceActive();
                    $role = ValidatedData::object(RouteExecutionProof::objects(ValidatedData::object($operation->input['intent'])['roles'])[$index]);
                    $digests = ValidatedData::stringMap(ValidatedData::object($role['intent'])['artifact_digests']);
                    if ($digests !== []) {
                        $entries = [];
                        foreach ($digests as $name => $digest) {
                            $entries[] = ['name' => $name, 'digest' => $digest];
                        }
                        app(NodeFootprintProjection::class)->record($operation,
                            ['node_id' => $node->id, 'artifacts' => $entries], ['footprint_proven' => false],
                            array_fill_keys(array_keys($digests), ['status' => 'applied']));
                    }
                    $certificates = ValidatedData::object($snapshot['certificates']);
                    foreach (RouteExecutionProof::objects($certificates['leaves']) as $leaf) {
                        $id = ValidatedData::integer($leaf['node_id']);
                        app(NodeRolePublicationState::class)->record($certificates, 'certificates_published', $id);
                        app(NodeRolePublicationState::class)->record($certificates, 'caddy_published', $id);
                    }
                    if ($snapshot['role'] === RoleName::Analytics->value) {
                        app(NodeRoleRuntimeProjection::class)->record($snapshot);
                    }
                } elseif ($status === 'failed' || $snapshot['created'] === true) {
                    $assignment->markConvergenceFailed(ValidatedData::string($failed), ValidatedData::string($data['error_code'] ?? 'node.enrollment_failed'));
                }
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node->refresh())->toArray());

            return $projected;
        });
    }

    /** @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private function observation(array $raw): ?array
    {
        $stdout = $this->stdout($raw, 'Bind the Node identity');
        if ($stdout === null) {
            return null;
        }

        return ValidatedData::object(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $raw */
    private function stdout(array $raw, string $name): ?string
    {
        $steps = ValidatedData::object($raw['steps'] ?? []);
        $step = ValidatedData::object($steps[$name] ?? []);

        return is_string($step['stdout'] ?? null) ? $step['stdout'] : null;
    }
}
