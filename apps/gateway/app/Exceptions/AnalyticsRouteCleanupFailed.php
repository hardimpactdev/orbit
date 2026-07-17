<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AnalyticsRouteCleanupFailed extends AnalyticsOperationFailed
{
    public function errorCode(): string
    {
        return 'analytics.route_cleanup_failed';
    }
}
