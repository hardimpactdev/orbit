<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class AppRegistrationResultAction
{
    /**
     * @param  list<array<string, string>>  $warnings
     */
    public function afterEnactment(string $action, array $warnings): string
    {
        if ($action !== 'converged') {
            return $action;
        }

        foreach ($warnings as $warning) {
            if (($warning['code'] ?? null) === 'proxy.enactment_failed') {
                return 'partial';
            }
        }

        return $action;
    }
}
