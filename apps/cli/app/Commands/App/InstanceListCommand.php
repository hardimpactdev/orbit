<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class InstanceListCommand extends InstanceCommand
{
    #[\Override]
    protected $signature = 'instance:list {--app= : Limit results to one app} {--json : Output JSON}';

    #[\Override]
    protected $description = 'List instances, optionally filtered by app.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/instances', array_filter(
                [
                    'app' => $this->stringOption('app'),
                ],
                is_string(...),
            ));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $instances = $this->instancesFromGatewayResponse($response);

        if ($instances === []) {
            $this->line('No instances found.');

            return self::SUCCESS;
        }

        table(
            headers: ['APP', 'NAME', 'DRIVER', 'MODE', 'PHP', 'EXTENSIONS', 'DEPLOYMENT'],
            rows: array_map(fn (array $instance): array => [
                $this->instanceString($instance, 'app'),
                $this->instanceString($instance, 'name'),
                $this->instanceString($instance, 'driver'),
                $this->runtimeString($instance, 'mode'),
                $this->runtimeString($instance, 'php_version'),
                $this->extensionsLabel($instance),
                $this->instanceString($instance, 'latest_deployment_status'),
            ], $instances),
        );

        return self::SUCCESS;
    }
}
