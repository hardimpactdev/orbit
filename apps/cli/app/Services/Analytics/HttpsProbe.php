<?php

declare(strict_types=1);

namespace App\Services\Analytics;

interface HttpsProbe
{
    /**
     * @param  list<string>  $addresses
     * @return array{completed: bool, http_status: int|null, tls_verified: bool, error: string|null}
     */
    public function get(string $url, array $addresses): array;
}
