<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\Nodes\ManagedUserAccount;
use App\Infrastructure\Nodes\RemoteNodeStorageRootPreparer;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteNodeStorageRootPreparer::class)]
interface NodeStorageRootPreparer
{
    public function inspect(Node $node, ManagedUserAccount $account, StoragePath $path): void;

    public function prepare(Node $node, ManagedUserAccount $account, StoragePath $root): void;
}
