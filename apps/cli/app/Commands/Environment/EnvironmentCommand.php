<?php

declare(strict_types=1);

namespace App\Commands\Environment;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Environment\EnvironmentOperationResponse;

abstract class EnvironmentCommand extends GatewayCommand
{
    protected function instanceSelector(): ?string
    {
        return $this->requiredStringOption('instance', 'Instance ID or Route domain', 'env.instance_required');
    }

    protected function renderEnvironmentOperation(EnvironmentOperationResponse $response): int
    {
        $payload = $response->toArray();

        if (in_array($response->operation, ['import', 'update'], strict: true)) {
            $payload['workload_file_changed'] = false;
        }

        if ($this->option('json') === true) {
            $this->writeJson($payload);

            return self::SUCCESS;
        }

        $fields = [
            'Instance ID' => $response->instanceId,
            'Operation' => $response->operation,
            'Changed' => $response->changed ? 'true' : 'false',
            'Stored keys' => $response->keyCount,
        ];

        if (in_array($response->operation, ['import', 'update'], strict: true)) {
            $fields['Workload file'] = 'unchanged';
        }

        if ($response->runtimeRefresh !== null) {
            $fields['Production PHP'] = match ($response->runtimeRefresh) {
                'config_cache_and_opcache_refreshed' => 'config cache rebuilt; FPM opcache reset',
                'opcache_refreshed_config_uncached' => 'FPM opcache reset; config remains uncached',
                'skipped_unchanged' => 'refresh skipped: environment file unchanged',
                'skipped_no_php_version' => 'refresh skipped: no PHP version selected',
                'skipped_no_current_release' => 'refresh skipped: no current release',
                default => $response->runtimeRefresh,
            };
        }

        if ($response->restartedProcessIds !== null) {
            $fields['Processes restarted'] = $response->restartedProcessIds === [] ? 'none' : implode(', ', $response->restartedProcessIds);
        }

        $fields['Request ID'] = $response->requestId;
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Instance environment', $fields));

        return self::SUCCESS;
    }
}
