<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\ResourceOperationException;

/** The agent's report shows a package Orbit can't manage later; `block` is the discovery block token. */
final class ToolAdoptionBlocked extends ResourceOperationException
{
    public function __construct(public readonly string $block)
    {
        parent::__construct('tool.adoption_unsupported', 'Orbit cannot manage this package later.', 409);
    }
}
