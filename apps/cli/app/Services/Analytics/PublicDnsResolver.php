<?php

declare(strict_types=1);

namespace App\Services\Analytics;

interface PublicDnsResolver
{
    /** @return list<array{type: string, value: string}> */
    public function resolve(string $host): array;
}
