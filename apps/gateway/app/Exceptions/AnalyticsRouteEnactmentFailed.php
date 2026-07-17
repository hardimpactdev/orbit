<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AnalyticsRouteEnactmentFailed extends AnalyticsOperationFailed
{
    public function errorCode(): string
    {
        return 'analytics.route_enactment_failed';
    }
}
