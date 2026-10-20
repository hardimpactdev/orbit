<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Nodes\SshManagedUserAccountResolver;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Singleton;

#[Bind(SshManagedUserAccountResolver::class)]
#[Singleton]
interface ManagedUserAccountResolver
{
    public function resolve(Node $node): ManagedUserAccount;
}
