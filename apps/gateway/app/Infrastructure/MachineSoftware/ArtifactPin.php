<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

final readonly class ArtifactPin
{
    /** @return list<string> Transport failures may fall back; integrity failures stop. */
    public function candidateUrls(string $name, string $mirror): array
    {
        return array_values(array_unique([
            ...(str_ends_with($this->location, '.deb') && $mirror !== '' ? [$mirror.'/'.$name.'_'.$this->digest.'.deb'] : []),
            $this->location,
        ]));
    }

    public function __construct(
        public string $location,
        public string $algorithm,
        public string $digest,
        public ?string $version = null,
    ) {}
}
