<?php

declare(strict_types=1);

namespace App\Infrastructure\Files;

use Illuminate\Container\Attributes\Bind;

#[Bind(NativeAtomicSymlinkPublisher::class)]
interface AtomicSymlinkPublisher
{
    public function publish(string $target, string $link): void;
}
