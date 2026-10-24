<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

/** Gateway intent for the native bootstrap; the agent owns packages, DNS, and account setup. */
final readonly class NodeBootstrapCommandFactory
{
    public function __construct(
        private SshKeyProvider $keys,
        private NodeBootstrapPackageCatalog $packages = new NodeBootstrapPackageCatalog,
    ) {}

    /** @return array{user: string, public_key: string, releases: list<string>, packages: list<string>, dns_policy: string} */
    public function input(Node $node, string $managedUser, bool $rolelessOperator = false): array
    {
        return [
            'user' => $managedUser,
            'public_key' => $this->keys->publicKey(),
            'releases' => UbuntuRelease::supportedCodenames(),
            'packages' => $this->packages->forNode($node),
            'dns_policy' => ! $rolelessOperator && $node->dns_server_override === null ? 'managed' : 'preserve',
        ];
    }
}
