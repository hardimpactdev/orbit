<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Infrastructure\Certificates\OpenSslLeafCertificateSigner;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Singleton;

#[Bind(OpenSslLeafCertificateSigner::class)]
#[Singleton]
interface LeafCertificateSigner
{
    public function sign(string $hostname, string $certificateRequest): string;

    public function rootCertificate(): string;
}
