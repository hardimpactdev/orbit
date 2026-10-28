<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class CaddyServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        NodeCaddyBuilds::class => NodeCaddyBuilder::class,
    ];

    public function register(): void
    {
        $this->app->scoped(
            NodeCaddyBuildLock::class,
            static fn (): NodeCaddyBuildLock => new NodeCaddyBuildLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/caddy-build',
                deadline: app(CommandDeadline::class),
            ),
        );
    }
}
