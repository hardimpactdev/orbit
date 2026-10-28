<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
use Illuminate\Support\ServiceProvider;

final class RemoteProgramsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        RemoteProgramRunner::class => SshProgramRunner::class,
    ];
}
