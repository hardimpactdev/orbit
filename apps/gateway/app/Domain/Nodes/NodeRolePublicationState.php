<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Support\ValidatedData;
use InvalidArgumentException;

/** Record trusted native phases before the next fresh public render.
 *
 * @phpstan-import-type CertificatePlan from NodeRoleCertificateInput
 */
final readonly class NodeRolePublicationState
{
    public function __construct(private CaddySiteCertificates $certificates, private WebSocketDnsTarget $websocket) {}

    /** The immutable plan determines every Node and site; native outputs supply no selectors.
     * A failed source withdrawal retains its serving mark and never clears the ready target.
     *
     * @param  array<string,mixed>  $snapshot
     */
    public function record(array $snapshot, string $phase, int $nodeId): void
    {
        $entries = match ($phase) {
            'certificates_published', 'caddy_published' => $snapshot['leaves'],
            'withdrawal_started', 'withdrawal_completed' => $snapshot['withdrawals'],
            default => throw new InvalidArgumentException('Unknown native role publication phase.'),
        };
        if (! is_array($entries)) {
            throw new InvalidArgumentException('Role publication entries must be a list.');
        }
        foreach ($entries as $raw) {
            $entry = ValidatedData::object($raw);
            if ($entry['node_id'] !== $nodeId) {
                continue;
            }
            $site = match ($entry['scope']) {
                'role-metrics' => 'metrics',
                'role-websocket' => 'websocket',
                'role-analytics' => 'analytics',
                default => throw new InvalidArgumentException('Unknown native role certificate scope.'),
            };
            match ($phase) {
                'certificates_published' => $this->certificates->record($nodeId, $site),
                'withdrawal_started' => $this->certificates->forget($nodeId, $site),
                'caddy_published' => $site === 'websocket' ? $this->websocket->markServing($nodeId) : null,
                'withdrawal_completed' => $site === 'websocket' ? $this->websocket->forget($nodeId) : null,
            };
        }
    }
}
