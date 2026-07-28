<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Node;

final readonly class RuntimeHibernationScope
{
    public function __construct(
        public string $type,
        public int $id,
        public Node $node,
        public ProcessOwnerContext $context,
    ) {}

    public function key(): string
    {
        return "{$this->type}-{$this->id}";
    }

    public function lockKey(): string
    {
        return "runtime-hibernation:{$this->key()}";
    }
}
