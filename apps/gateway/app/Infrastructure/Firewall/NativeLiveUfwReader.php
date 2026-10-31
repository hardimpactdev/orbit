<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\LiveFirewallBackendStatus;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Throwable;

/** Reads the latest native inspection snapshot. A GET never starts operational work. */
final readonly class NativeLiveUfwReader
{
    /** @return array{backend:LiveFirewallBackendStatus,shapes:list<UfwRuleShape>,observed_at:?string} */
    public function read(Node $node): array
    {
        $unavailable = ['backend' => LiveFirewallBackendStatus::Unreachable, 'shapes' => [], 'observed_at' => null];
        if ($node->platform !== 'linux') {
            return $unavailable;
        }
        $operation = Operation::query()->where('kind', 'firewall.inspect')->where('target_type', 'node_firewall')
            ->where('target_id', $node->id)->where('node_id', $node->id)->orderByDesc('id')->first();
        if ($operation === null || $operation->status !== OperationStatus::Succeeded) {
            return $unavailable;
        }
        try {
            $result = $operation->result ?? [];
            $backend = LiveFirewallBackendStatus::from(ValidatedData::string($result['backend'] ?? null));
            $observedAt = ValidatedData::string($result['observed_at'] ?? null);
            if (! is_array($result['live'] ?? null) || ! array_is_list($result['live'])) {
                return $unavailable;
            }

            return ['backend' => $backend, 'observed_at' => $observedAt,
                'shapes' => array_map(static fn (mixed $row): UfwRuleShape => UfwRuleShape::fromAgentOutput(ValidatedData::object($row)), $result['live'])];
        } catch (Throwable) {
            return $unavailable;
        }
    }
}
