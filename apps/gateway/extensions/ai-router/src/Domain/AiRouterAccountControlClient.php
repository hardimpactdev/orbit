<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

interface AiRouterAccountControlClient
{
    public function setDisabled(string $wireguardIp, int $port, string $controlToken, string $account, bool $disabled): void;
}
