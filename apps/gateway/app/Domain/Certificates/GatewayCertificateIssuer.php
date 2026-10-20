<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Infrastructure\Certificates\OpenSslGatewayCertificateIssuer;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Singleton;

#[Bind(OpenSslGatewayCertificateIssuer::class)]
#[Singleton]
interface GatewayCertificateIssuer
{
    public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths;
}
