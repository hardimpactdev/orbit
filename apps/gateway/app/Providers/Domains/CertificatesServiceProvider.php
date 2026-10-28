<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateIssuer;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateValidator;
use App\Infrastructure\Certificates\OpenSslLeafCertificateSigner;
use App\Infrastructure\Files\NativeAtomicSymlinkPublisher;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class CertificatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            LeafCertificateSigner::class,
            static fn (): LeafCertificateSigner => new OpenSslLeafCertificateSigner(
                processes: app(ProcessRunner::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            GatewayCertificateIssuer::class,
            static fn (): GatewayCertificateIssuer => new OpenSslGatewayCertificateIssuer(
                processes: app(ProcessRunner::class),
                validator: app(OpenSslGatewayCertificateValidator::class),
                links: app(NativeAtomicSymlinkPublisher::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
    }
}
