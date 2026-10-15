<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\TaskVms\ModelKeyIssuer;
use App\Infrastructure\ProxyCli\ProxyCliModelKeyIssuer;

/** The proxycli extension. Its code is still in core ([Extensions](/reference/extensions#the-extensions)). */
final class ProxyCliServiceProvider extends ExtensionServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ModelKeyIssuer::class => ProxyCliModelKeyIssuer::class,
    ];

    public function name(): string
    {
        return 'proxycli';
    }

    public function description(): string
    {
        return 'Collects CLIProxyAPI provider quota and shows it on the Quota page.';
    }
}
