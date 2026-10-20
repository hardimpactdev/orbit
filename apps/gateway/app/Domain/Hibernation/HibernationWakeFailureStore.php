<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Infrastructure\Hibernation\CacheHibernationWakeFailureStore;
use Illuminate\Container\Attributes\Bind;

#[Bind(CacheHibernationWakeFailureStore::class)]
interface HibernationWakeFailureStore
{
    public function remember(int $instanceId, string $message): void;

    public function pull(int $instanceId): ?string;
}
