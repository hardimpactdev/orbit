<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Releases\GatewayReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertWebhook;
use App\Infrastructure\Releases\HttpReleaseAlertWebhook;
use App\Infrastructure\Releases\ReleaseProvenance;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class ReleasesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ReleaseAlertNotifier::class => GatewayReleaseAlertNotifier::class,
        ReleaseAlertWebhook::class => HttpReleaseAlertWebhook::class,
    ];

    public function register(): void
    {
        $this->app->bind(
            ReleaseProvenance::class,
            static fn (): ReleaseProvenance => new ReleaseProvenance(
                repository: Config::string('orbit.cli_releases.repository'),
                trustRoot: Config::string('orbit.cli_releases.trusted_root'),
            ),
        );
    }
}
