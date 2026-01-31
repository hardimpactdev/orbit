<?php

declare(strict_types=1);

namespace App\Commands\Host;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyManager;
use App\Services\HorizonManager;
use App\Services\PhpManager;
use LaravelZero\Framework\Commands\Command;

final class HostStartCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'host:start {service} {--json}';

    protected $description = 'Start a host service';

    public function handle(
        CaddyManager $caddy,
        PhpManager $php,
        HorizonManager $horizon
    ): int {
        $service = $this->argument('service');

        try {
            if ($service === 'caddy') {
                $success = $caddy->start();
            } elseif (str_starts_with($service, 'php')) {
                $version = str_replace('php-', '', $service);
                $success = $php->start($version);
            } elseif ($service === 'horizon') {
                $success = $horizon->start();
            } else {
                if ($this->wantsJson()) {
                    return $this->outputJsonError("Unknown host service: {$service}", ExitCode::InvalidArguments->value);
                }
                $this->error("Unknown host service: {$service}");

                return ExitCode::InvalidArguments->value;
            }

            if ($this->wantsJson()) {
                return $success
                    ? $this->outputJsonSuccess(['message' => "Started {$service}"])
                    : $this->outputJsonError("Failed to start {$service}", ExitCode::ServiceFailed->value);
            }

            if ($success) {
                $this->info("Started {$service}");

                return self::SUCCESS;
            }

            $this->error("Failed to start {$service}");

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
