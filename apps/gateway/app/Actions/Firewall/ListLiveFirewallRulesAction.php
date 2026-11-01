<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteExecutionProof;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Present the native comparison; a GET does not observe a host or recompute drift. */
final readonly class ListLiveFirewallRulesAction
{
    /** @return array{backend_status:string,observed_at:?string,live:list<array<string,mixed>>,missing:list<array<string,mixed>>} */
    public function execute(Node $node): array
    {
        $unavailable = ['backend_status' => 'unreachable', 'observed_at' => null, 'live' => [], 'missing' => []];
        if ($node->platform !== 'linux') {
            return $unavailable;
        }
        $operation = Operation::query()->where('kind', 'firewall.inspect')->where('target_type', 'node_firewall')
            ->where('target_id', $node->id)->where('node_id', $node->id)->orderByDesc('id')->first();
        if ($operation === null || $operation->status !== OperationStatus::Succeeded) {
            return $unavailable;
        }
        $result = $operation->result ?? [];
        if (! isset($result['comparison'])) {
            return $unavailable;
        }
        $comparison = ValidatedData::object($result['comparison']);

        return ['backend_status' => ValidatedData::string($result['backend']), 'observed_at' => ValidatedData::string($result['observed_at']),
            'live' => RouteExecutionProof::objects($comparison['live']), 'missing' => RouteExecutionProof::objects($comparison['missing'])];
    }
}
