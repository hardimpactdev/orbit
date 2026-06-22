<?php

declare(strict_types=1);

namespace App\Services\Php;

use InvalidArgumentException;

/**
 * E2E-local mirror of the gateway PHP runtime catalog.
 *
 * The external harness needs to know which FrankenPHP runtime images the
 * gateway prepares so it can build/inspect Docker and Incus topologies. This
 * is a pure value catalog (no gateway internals) and is kept in sync with
 * apps/gateway/app/Services/Php/PhpRuntimeCatalog.
 */
final readonly class PhpRuntimeCatalog
{
    /** @var list<string> */
    public const array SUPPORTED = ['8.5', '8.4', '8.3'];

    public const string DEFAULT = '8.5';

    public const string IMAGE_REPOSITORY = 'ghcr.io/hardimpactdev/orbit-frankenphp';

    public const string IMAGE_MAJOR = '1';

    public const string IMAGE_DISTRIBUTION = 'bookworm';

    public function supports(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }

    public function imageFor(string $version): string
    {
        $version = trim($version);

        if (! $this->supports($version)) {
            throw new InvalidArgumentException("Unsupported PHP version '{$version}'.");
        }

        return self::IMAGE_REPOSITORY.':'.self::IMAGE_MAJOR."-php{$version}-".self::IMAGE_DISTRIBUTION;
    }

    public function versionForImage(string $image): string
    {
        $image = trim($image);

        foreach ($this->supported() as $version) {
            if ($image === $this->imageFor($version)) {
                return $version;
            }
        }

        throw new InvalidArgumentException("Unsupported PHP runtime image '{$image}'.");
    }

    public function isApprovedImage(string $image): bool
    {
        try {
            $this->versionForImage($image);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return self::SUPPORTED;
    }

    /**
     * @return list<string>
     */
    public function supportedImages(): array
    {
        return array_map($this->imageFor(...), $this->supported());
    }
}
