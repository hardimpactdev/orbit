<?php

declare(strict_types=1);

namespace App\Actions\AppDev;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\AppDev\PrivateDnsPublication;
use App\Models\Node;
use App\Models\Operation;

/**
 * Records a fresh render of the private DNS as a `dns.publish` operation for one vpn Node. Swamp writes it.
 * One publication runs per Node at a time: a second admission finds the Node busy and the caller waits, then renders again.
 */
final readonly class AdmitPrivateDnsPublicationAction
{
    public function __construct(private PrivateDnsPublication $publication, private StartOperationAction $start) {}

    public function execute(Node $node, ?string $triggeredBy = null, ?string $requestId = null, bool $clusterOnly = false): Operation
    {
        return $this->start->execute('dns.publish', 'private_dns', $node->id, $node->id, ['intent' => $this->publication->entry($node, $clusterOnly)], $triggeredBy, $requestId,
            lockKey: self::lockKey($node));
    }

    public static function lockKey(Node $node): string
    {
        return OperationStore::lockKey('private_dns', $node->id);
    }
}
