<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Infrastructure\AppDev\NativeAppDevTldConverger;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeAppDevTldConverger::class)]
interface AppDevTldConverger
{
    public function converge(Node $node): void;
}
