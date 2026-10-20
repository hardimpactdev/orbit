<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeRuntimeHibernatorConverger::class)]
interface RuntimeHibernatorConverger
{
    public function converge(): void;
}
