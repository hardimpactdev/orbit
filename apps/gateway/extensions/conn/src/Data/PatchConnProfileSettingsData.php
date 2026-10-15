<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Data;

final class PatchConnProfileSettingsData extends Data
{
    /**
     * @param  array<string, int>  $versions  the version of each section that the client last read, by section name
     * @param  array<string, mixed>  $values  the new value of each section; null removes it
     */
    public function __construct(
        public array $versions,
        public array $values,
    ) {}
}
