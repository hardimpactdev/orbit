<?php

declare(strict_types=1);

namespace App\Domain\AppProd;

use App\Infrastructure\AppProd\RemoteAppProdCaddyManager;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteAppProdCaddyManager::class)]
interface AppProdCaddyManager
{
    public function converge(Node $node): void;

    public function remove(Node $node): void;
}
