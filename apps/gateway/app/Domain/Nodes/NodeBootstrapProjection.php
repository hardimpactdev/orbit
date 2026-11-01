<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ToolManagerRecord;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records native observations without activating the candidate account. */
final readonly class NodeBootstrapProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = ['bootstrap' => $operation->input['bootstrap']];

        return $operation->kind === 'node.bootstrap_probe'
            ? ['intent' => $intent, 'execution_secrets' => $operation->input['execution_secrets']]
            : $intent;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip([
            'probed', 'installed', 'changed', 'bootstrapped', 'bootstrap_verified', 'architecture', 'host_type', 'public_key',
            'host_key_fingerprint', 'account', 'error_code',
        ]));

        return DB::transaction(function () use ($operation, $data, $result): Operation {
            $operation = app(DirectOperation::class)->project($operation, $data, $result);
            if ($operation->status->value === 'succeeded') {
                $changes = array_intersect_key($result, array_flip(['architecture'])) +
                    array_filter(['ssh_host_key_type' => $result['host_type'] ?? null, 'ssh_host_key' => $result['public_key'] ?? null,
                        'ssh_host_fingerprint' => $result['host_key_fingerprint'] ?? null], static fn ($value): bool => $value !== null);
                if ($changes !== []) {
                    Node::query()->findOrFail($operation->target_id)->update($changes);
                }
                // The host bootstrap installs through apt, so apt is a ready Tool Manager from here on (flows/tool.install.mmd).
                if ($operation->kind === 'node.bootstrap_host') {
                    ToolManagerRecord::query()->firstOrCreate(['node_id' => $operation->target_id, 'name' => ToolManagerName::Apt->value], ['status' => LifecycleStatus::Active]);
                }
            }

            return $operation;
        });
    }
}
