<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

interface LeafCertificateSigner
{
    public function rootCertificate(): string;
}
