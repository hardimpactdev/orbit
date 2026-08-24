<?php

declare(strict_types=1);

namespace App\Support\Csp;

use Illuminate\Support\Facades\Vite;
use Spatie\Csp\Nonce\NonceGenerator;
use Spatie\Csp\Nonce\RandomString;

class LaravelViteNonceGenerator implements NonceGenerator
{
    public function generate(): string
    {
        return Vite::cspNonce() ?? RandomString::class;
    }
}
