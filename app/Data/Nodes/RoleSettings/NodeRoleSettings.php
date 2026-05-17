<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

interface NodeRoleSettings
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
