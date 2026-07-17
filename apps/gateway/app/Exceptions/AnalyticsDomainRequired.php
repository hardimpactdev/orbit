<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AnalyticsDomainRequired extends AnalyticsOperationFailed
{
    public function errorCode(): string
    {
        return 'analytics.domain_required';
    }
}
