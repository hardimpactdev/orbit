<?php

namespace App\Commands\Host;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use LaravelZero\Framework\Commands\Command;

class HostStopCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'host:stop {service} {--json}';

    protected $description = 'Stop a host service';

    public function handle(
        CaddyManager $caddy,
        PhpManager $php,
        HorizonManager $horizon
    ): int {
        $service = $this->argument('service');

        try {
            if ($service === 'caddy') {
                $success = $caddy->stop();
            } elseif (str_starts_with($service, 'php')) {
                $version = str_replace('php-', '', $service);
                $success = $php->stop($version);
            } elseif ($service === 'horizon') {
                $success = $horizon->stop();
            } else {
                if ($this->wantsJson()) {
                    return $this->outputJsonError("Unknown host service: {$service}", ExitCode::InvalidArguments->value);
                }
                $this->error("Unknown host service: {$service}");

                return ExitCode::InvalidArguments->value;
            }

            if ($this->wantsJson()) {
                return $success
                    ? $this->outputJsonSuccess(['message' => "Stopped {$service}"])
                    : $this->outputJsonError("Failed to stop {$service}", ExitCode::ServiceFailed->value);
            }

            if ($success) {
                $this->info("Stopped {$service}");

                return self::SUCCESS;
            }

            $this->error("Failed to stop {$service}");

            return ExitCode::ServiceFailed->value;

        } catch (\Exception $e) {
            if ($this->wantsJson()) {
                return $this->outputJsonError($e->getMessage());
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
