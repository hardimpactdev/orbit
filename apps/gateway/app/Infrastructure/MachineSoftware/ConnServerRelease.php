<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

final readonly class ConnServerRelease
{
    public function __construct(public string $version, public ArtifactPin $linux, public MacAppPin $mac) {}
}
