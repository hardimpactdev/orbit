<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Domain\Routes\ReservedPrivateHostname;

/** The reserved collector hostname. The apex stays free for the CLIProxyAPI management Route. */
final readonly class ProxyCliHostname
{
    public const string Value = 'collector.'.self::Apex;

    public const string Apex = 'cli-proxy-api.'.ReservedPrivateHostname::PrivateSpace;
}
