<?php

declare(strict_types=1);

namespace App\Commands\App;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class InstanceListCommand extends InstanceCommand
{
    #[\Override]
    protected $signature = 'instance:list {--project= : Limit results to one project} {--json : Output JSON}';

    #[\Override]
    protected $description = 'List instances, optionally filtered by project.';

    public function handle(): int
    {
        try {
            $response = $this->gatewayGet('/api/instances', array_filter(
                [
                    'project' => $this->stringOption('project'),
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
            headers: ['PROJECT', 'NAME', 'DRIVER', 'MODE', 'PHP', 'EXTENSIONS', 'DEPLOYMENT'],
            rows: array_map(fn (array $instance): array => [
                $this->instanceString($instance, 'project'),
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
