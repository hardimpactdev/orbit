<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

interface HibernationWakeFailureStore
{
    public function remember(int $instanceId, string $message): void;

    public function get(int $instanceId): ?string;

    public function forget(int $instanceId): void;
}
