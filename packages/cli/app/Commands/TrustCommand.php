<?php

namespace App\Commands;

use App\Actions\Install\Mac\TrustRootCa as MacTrustRootCa;
use App\Actions\Install\Linux\TrustRootCa as LinuxTrustRootCa;
use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use LaravelZero\Framework\Commands\Command;

class TrustCommand extends Command
{
    protected $signature = 'trust';

    protected $description = 'Trust Caddy root CA certificate for local HTTPS';

    public function handle(): int
    {
        $this->info('Trusting Caddy SSL certificate...');
        $this->newLine();

        $context = InstallContext::fromOptions([]);
        $logger = new InstallLogger($this);

        $action = PHP_OS_FAMILY === 'Darwin'
            ? app(MacTrustRootCa::class)
            : app(LinuxTrustRootCa::class);

        $result = $action->handle($context, $logger);

        if ($result->isFailed()) {
            $this->error($result->error ?? 'Unknown error');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done! Your .test sites should now show as secure.');

        return self::SUCCESS;
    }
}
