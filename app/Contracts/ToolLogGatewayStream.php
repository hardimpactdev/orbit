<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Http\Gateway\GatewayApiException;

interface ToolLogGatewayStream
{
    /**
     * @param  callable(string): void  $onOutput
     */
    public function follow(string $tool, ?string $node, ?string $app, int $lines, callable $onOutput): int|GatewayApiException;
}
