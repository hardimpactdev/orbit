<?php

declare(strict_types=1);

namespace App\Services\E2E;

final readonly class IncusE2EImagePreparationResult
{
    /**
     * @param  list<array{role: string, alias: string, action: string}>  $images
     */
    public function __construct(public array $images) {}
}
