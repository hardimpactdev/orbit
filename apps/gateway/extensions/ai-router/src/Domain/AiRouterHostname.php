<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Routes\ReservedPrivateHostname;

/** The reserved collector hostname. The apex stays free for the CLIProxyAPI management Route. */
final readonly class AiRouterHostname
{
    public const string Value = 'collector.'.self::Apex;

    public const string Apex = 'ai-router.'.ReservedPrivateHostname::PrivateSpace;
}
