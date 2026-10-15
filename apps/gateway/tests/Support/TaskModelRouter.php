<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\ProxyCli\ProxyCliState;

/** The model router that the tasks extension needs before it can be enabled: proxycli on and set up with a management key. */
final class TaskModelRouter
{
    public static function ready(): void
    {
        app(ExtensionStore::class)->set('proxycli', true);
        app(ProxyCliState::class)->enable(1, 'valkey', 'http://10.44.0.3:8317', 'management-key', 'read-token', 'control-token');
    }
}
