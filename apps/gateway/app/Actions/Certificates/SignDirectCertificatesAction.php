<?php

declare(strict_types=1);

namespace App\Actions\Certificates;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Operations\DirectOperation;
use App\Domain\Routes\RouteDomain;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Certificates\GatewayLeafCertificateSigner;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** Root-of-trust issuance only. Every selector comes from the original admitted Operation. */
final readonly class SignDirectCertificatesAction
{
    public function __construct(private DirectOperation $direct, private LeafCertificateSigner $root, private GatewayLeafCertificateSigner $signer) {}

    /**
     * @param  list<array{node_id:int,scope:string,state:string,request_base64?:string}>  $requests
     * @return list<array{node_id:int,scope:string,certificate_base64:string,root_certificate_base64:string}>
     */
    public function handle(Operation $operation, int $callerNodeId, string $runId, array $requests, bool $restoring = false): array
    {
        $leaves = $this->authorize($operation, $callerNodeId, $runId, $restoring);
        $root = $this->root->rootCertificate();
        $selected = [];
        foreach ($requests as $request) {
            $key = $request['node_id'].':'.$request['scope'];
            $leaf = $leaves[$key] ?? null;
            if ($leaf === null || isset($selected[$key]) || ! hash_equals($leaf['root_hash'], hash('sha256', $root))) {
                throw new ResourceOperationException('certificate.intent_mismatch', 'The certificate does not match the admitted leaf and root.', 409);
            }
            $selected[$key] = ['leaf' => $leaf, 'request' => $request];
        }
        $certificates = [];
        foreach ($selected as $selection) {
            ['leaf' => $leaf, 'request' => $request] = $selection;
            if ($request['state'] === 'current') {
                continue;
            }
            $csr = is_string($request['request_base64'] ?? null) ? base64_decode($request['request_base64'], true) : false;
            if (! is_string($csr) || strlen($csr) > 16384) {
                throw new ResourceOperationException('certificate.request_invalid', 'A valid certificate request is required.', 422);
            }
            $certificate = $this->signer->sign($leaf['domain'], $csr, $root);
            $certificates[] = ['node_id' => $leaf['node_id'], 'scope' => $leaf['scope'],
                'certificate_base64' => base64_encode($certificate), 'root_certificate_base64' => base64_encode($root)];
        }
        if ($this->authorize($operation, $callerNodeId, $runId, $restoring) !== $leaves
            || ! hash_equals($root, $this->root->rootCertificate())) {
            throw new ResourceOperationException('certificate.intent_mismatch', 'The admitted certificate intent changed during signing.', 409);
        }
        foreach ($certificates as $certificate) {
            Activity::query()->create([
                'request_id' => $operation->request_id ?? (string) Str::uuid(),
                'command' => 'operation:certificate:sign', 'description' => 'Signed an admitted public leaf certificate.',
                'subject_type' => Operation::class, 'subject_id' => $operation->id, 'status' => 'succeeded', 'exit_code' => 0,
                'properties' => ['operation_id' => $operation->id, 'run_id' => $runId, 'caller_node_id' => $callerNodeId,
                    'node_id' => $certificate['node_id'], 'scope' => $certificate['scope'],
                    'domain' => $leaves[$certificate['node_id'].':'.$certificate['scope']]['domain'], 'root_hash' => hash('sha256', $root)],
            ]);
        }

        return $certificates;
    }

    /** @return array<string,array{node_id:int,scope:string,domain:string,root_hash:string}> */
    private function authorize(Operation $operation, int $callerNodeId, string $runId, bool $restoring): array
    {
        $operation->refresh();
        $this->direct->projection($operation);
        $this->direct->assertRun($operation, $runId);
        if ($operation->node_id === null || ! in_array($callerNodeId, [$operation->node_id, 209], true)) {
            throw new ResourceOperationException('operation.executor_forbidden', 'Only the root Node or canonical certificate signer may sign this intent.', 403);
        }
        if ($operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw new ResourceOperationException('operation.finished', 'This intent no longer permits certificate signing.', 409);
        }
        $paths = match ($operation->kind) {
            'certificate.converge' => [''],
            'route.update' => $restoring
                ? ['route_update.route_mutations.restore.certificates']
                : ['route_update.route_mutations.forward.prepare_certificates', 'route_update.route_mutations.forward.certificates'],
            'route.target_set' => $restoring
                ? ['route_target.route_mutations.restore.certificates']
                : ['route_target.preparation.certificates', 'route_target.route_mutations.forward.certificates'],
            'route.create', 'route.converge', 'cluster.router_set', 'cluster.router_unset' => ['certificates'],
            'analytics.enable', 'analytics.disable' => ['tracking.certificates'],
            'metrics.enable' => ['certificates.leaves'],
            'node.role_add', 'node.role_converge' => ['_role.certificates.leaves'],
            'node.role_relocate' => ['_relocate.certificates.leaves'],
            'node.role_remove' => ['_role_remove.certificates.leaves'],
            'node.remove' => $restoring ? ['_removal.restore_certificates'] : [],
            'instance.transfer' => ['route_transfer.target.certificates'],
            default => [],
        };
        $leaves = [];
        foreach ($paths as $path) {
            $rows = $path === '' ? [$operation->input] : Arr::get($operation->input, $path, []);
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (! is_array($row) || ! is_int($row['node_id'] ?? null) || ! is_string($row['scope'] ?? null)
                    || ! is_string($row['domain'] ?? null) || ! RouteDomain::isValid($row['domain'])
                    || ! is_string($row['root_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $row['root_hash']) !== 1
                    || ! Node::query()->whereKey($row['node_id'])->where('status', 'active')->exists()) {
                    continue;
                }
                $leaf = ['node_id' => $row['node_id'], 'scope' => $row['scope'], 'domain' => $row['domain'], 'root_hash' => $row['root_hash']];
                $key = $row['node_id'].':'.$row['scope'];
                if (isset($leaves[$key]) && $leaves[$key] !== $leaf) {
                    throw new ResourceOperationException('certificate.intent_mismatch', 'The admitted certificate scope is ambiguous.', 409);
                }
                $leaves[$key] = $leaf;
            }
        }
        if ($leaves === []) {
            throw new ResourceOperationException('certificate.intent_missing', 'This Operation has no admitted leaf signing intent.', 403);
        }

        return $leaves;
    }
}
