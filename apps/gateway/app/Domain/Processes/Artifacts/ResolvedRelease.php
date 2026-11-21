<?php

declare(strict_types=1);

namespace App\Domain\Processes\Artifacts;

/** One upstream release asset that the Gateway resolved at admission. */
final readonly class ResolvedRelease
{
    public function __construct(public string $version, public string $tag, public string $asset, public string $url, public string $sha256) {}
}
