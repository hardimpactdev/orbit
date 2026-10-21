<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DatabaseServers\DatabaseServerFirewall;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final class FakeDatabaseServerFirewall implements DatabaseServerFirewall
{
    /** @var list<array{node: int, rules: list<string>, expand: bool}> */
    public array $calls = [];

    public bool $fail = false;

    public bool $matching = true;

    public function reconcile(Node $node, array $rules, bool $expand = false): void
    {
        $this->calls[] = ['node' => $node->id, 'rules' => $rules, 'expand' => $expand];
        if ($this->fail) {
            throw new ResourceOperationException('database.server_firewall_failed', 'The database filter failed.', 502);
        }
    }

    public function matches(Node $node, array $rules): bool
    {
        return $this->matching;
    }
}
