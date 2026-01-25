<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\Install\Linux\TrustRootCa as LinuxTrustRootCa;
use App\Actions\Install\Mac\TrustRootCa as MacTrustRootCa;
use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use LaravelZero\Framework\Commands\Command;

final class TrustRootCaCommand extends Command
{
    protected $signature = 'trust-root-ca';

    protected $description = 'Trust Caddy\'s root CA certificate for HTTPS';

    public function handle(): int
    {
        $context = InstallContext::fromOptions([]);
        $logger = new InstallLogger($this);

        $logger->step('Trusting SSL certificate');

        $action = PHP_OS_FAMILY === 'Darwin'
            ? app(MacTrustRootCa::class)
            : app(LinuxTrustRootCa::class);

        $result = $action->handle($context, $logger);

        if ($result->isFailed()) {
            $logger->error($result->error ?? 'Unknown error');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
