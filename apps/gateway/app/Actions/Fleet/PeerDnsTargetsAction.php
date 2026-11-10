<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Operations\OperationStatus;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class PeerDnsTargetsAction
{
    public function __construct(private PeerDnsPolicy $policy) {}

    /**
     * Managed peers whose policy differs from the one their last successful peer DNS run applied, in Node order.
     *
     * @return list<array{node_id:int, configuration_digest:string}>
     */
    public function execute(): array
    {
        $applied = Operation::query()
            ->whereIn('id', Operation::query()->selectRaw('max(id)')->where('kind', 'fleet.peer_dns')
                ->where('status', OperationStatus::Succeeded)->groupBy('target_id'))
            ->get()->mapWithKeys(function (Operation $operation): array {
                $intent = ValidatedData::object($operation->input['intent'] ?? []);

                return [$operation->target_id => self::digest($intent['server'] ?? null, $intent['public_key'] ?? null, $intent['domains'] ?? null, $intent['selection'] ?? null)];
            });

        $targets = Node::query()->with('roles')->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $this->policy->manages($node))
            ->map(fn (Node $node): array => [
                'node_id' => $node->id,
                'configuration_digest' => self::digest($this->policy->server($node), $node->wireguard_public_key, $this->policy->domains($node), $this->policy->selection($node)),
            ])
            ->reject(fn (array $target): bool => ($applied[$target['node_id']] ?? null) === $target['configuration_digest'])
            ->all();

        return array_values($targets);
    }

    /** The admitted policy: DNS server, the peer key it belongs to, and routing domains. */
    public static function digest(mixed $server, mixed $publicKey, mixed $domains, mixed $selection = null): string
    {
        if (is_array($selection)) {
            unset($selection['revision']);
        }

        return hash('sha256', json_encode([$server, $publicKey, $domains, $selection], JSON_THROW_ON_ERROR));
    }
}
