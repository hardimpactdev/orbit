<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AnalyticsMutationBusy extends AnalyticsOperationFailed
{
    public function errorCode(): string
    {
        return 'analytics.mutation_busy';
    }
}
