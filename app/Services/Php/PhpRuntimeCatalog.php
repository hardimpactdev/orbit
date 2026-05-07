<?php

declare(strict_types=1);

namespace App\Services\Php;

final readonly class PhpRuntimeCatalog
{
    /** @var list<string> */
    public const array SUPPORTED = ['8.5', '8.4', '8.3'];

    public const string DEFAULT = '8.5';

    public function supports(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return self::SUPPORTED;
    }
}
