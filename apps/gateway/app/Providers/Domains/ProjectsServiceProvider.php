<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Infrastructure\Projects\NativeProjectUpdateProjectionMutator;
use App\Infrastructure\Projects\RemoteProjectUpdateSourceMutator;
use Illuminate\Support\ServiceProvider;

final class ProjectsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ProjectUpdateSourceMutator::class => RemoteProjectUpdateSourceMutator::class,
        ProjectUpdateProjectionMutator::class => NativeProjectUpdateProjectionMutator::class,
    ];
}
