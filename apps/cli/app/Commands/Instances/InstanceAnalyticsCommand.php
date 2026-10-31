<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Analytics\ShowInstanceAnalyticsRequest;
use Orbit\Sdk\Responses\Analytics\InstanceAnalyticsResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** What the three `instance:analytics:*` commands share: one answer, rendered one way. */
abstract class InstanceAnalyticsCommand extends GatewayCommand
{
    use FollowsOperations;

    protected function finishOperation(GatewayConnector $connector, GatewayConfigRepository $repository, OperationResponse $operation, int $instanceId, string $headline): int
    {
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $status = $this->followOperation($connector, $profile, $operation, 'Update analytics', 'Analytics');
        if ($status !== self::SUCCESS || $this->option('json') === true) {
            return $status;
        }
        $tracking = $this->send($connector, new ShowInstanceAnalyticsRequest($instanceId), InstanceAnalyticsResponse::class);

        return $tracking instanceof InstanceAnalyticsResponse ? $this->renderAnalytics($tracking, $headline) : self::FAILURE;
    }

    protected function renderAnalytics(InstanceAnalyticsResponse $response, string $headline): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($headline, [
            'Instance' => "#{$response->instanceId}",
            'Domain' => $response->domain,
            'Tracking' => $response->enabled ? 'enabled' : 'disabled',
            'Dashboard' => $response->dashboardUrl,
            'Request ID' => $response->requestId,
        ]));

        if ($response->hosts === []) {
            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Host', 'Route', 'Status', 'Publication'],
            array_map(static fn (array $host): array => [
                $host['host'],
                (string) $host['route_id'],
                $host['error_code'] === null ? $host['status'] : "{$host['status']} ({$host['error_code']})",
                $host['publication'],
            ], $response->hosts),
        ));

        // Whole lines, not table cells: a host name is long, and a wrapped DNS record is hard to copy.
        $records = array_filter($response->hosts, static fn (array $host): bool => $host['dns'] !== null);

        if ($records !== []) {
            $this->writeHumanMessage('Create these DNS records:');

            foreach ($records as $host) {
                $this->writeHumanMessage("  {$host['dns']['type']} {$host['dns']['name']} -> {$host['dns']['value']}");
            }
        }

        if ($response->snippet !== null) {
            $this->writeHumanMessage('Add this tag to the Project:');
            $this->writeHumanMessage($response->snippet);
        }

        return self::SUCCESS;
    }
}
