<?php

declare(strict_types=1);

namespace App\Providers;

/** The proxycli extension. Its code is still in core ([Extensions](/reference/extensions#the-extensions)). */
final class ProxyCliServiceProvider extends ExtensionServiceProvider
{
    public function name(): string
    {
        return 'proxycli';
    }

    public function description(): string
    {
        return 'Collects CLIProxyAPI provider quota and shows it on the Quota page.';
    }
}
