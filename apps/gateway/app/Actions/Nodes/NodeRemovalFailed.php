<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use RuntimeException;
use Throwable;

final class NodeRemovalFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $meta,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function selfRequiresRemoteCaller(string $name): self
    {
        return new self(
            errorCode: 'node.self_removal_requires_remote_caller',
            status: 422,
            meta: ['name' => $name],
            message: 'Remove this node from the gateway or another authorized node.',
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function wireGuardPeerRemoval(string $name, array $meta, Throwable $previous): self
    {
        return new self(
            errorCode: 'node.wireguard_peer_removal_failed',
            status: 502,
            meta: $meta,
            message: "Node '{$name}' could not be removed because its WireGuard peer could not be detached.",
            previous: $previous,
        );
    }
}
