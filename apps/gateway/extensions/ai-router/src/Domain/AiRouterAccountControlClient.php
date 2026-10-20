<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use Illuminate\Container\Attributes\Bind;
use Orbit\Extensions\AiRouter\Infrastructure\HttpAiRouterAccountControlClient;

#[Bind(HttpAiRouterAccountControlClient::class)]
interface AiRouterAccountControlClient
{
    public function setDisabled(string $wireguardIp, int $port, string $controlToken, string $account, bool $disabled): void;
}
