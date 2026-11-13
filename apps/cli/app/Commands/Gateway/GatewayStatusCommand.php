<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;

final class GatewayStatusCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:status
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the active gateway status.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $progress = $this->progressDisplay("Check gateway: {$profile->name}");
        $progress->admit('status', 'Request status', 'Requesting status', 'Received status');
        $status = $progress->during('status', fn () => $this->send(
            $connectors->make($profile),
            new ShowGatewayStatusRequest,
            GatewayStatusResponse::class,
        ));

        if (! $status instanceof GatewayStatusResponse) {
            $progress->complete('status', ProgressState::Failure);
            $progress->finish('Gateway status unavailable.');

            return self::FAILURE;
        }

        $progress->complete('status', ProgressState::Success);
        $progress->finish('Gateway status received.');

        $payload = [
            'gateway' => $profile->name,
            'url' => $profile->url,
            ...$status->toArray(),
        ];

        if ($this->option('json') === true) {
            $this->writeJson($payload);

            return self::SUCCESS;
        }

        $fleet = $status->desiredFleetState;

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Gateway: {$profile->name}", [
            'URL' => $profile->url,
            'Name' => $status->name,
            'Status' => $status->status,
            'Version' => $status->version,
            'PHP version' => $status->phpVersion,
            'Laravel version' => $status->laravelVersion,
            ...($fleet === null ? [] : [
                'CLI release' => $fleet->cli->version ?? 'unavailable: '.($fleet->cli->reason ?? 'unknown'),
                'Agent version' => $fleet->agent->version,
            ]),
            'Release' => $status->release,
            'Automatic releases' => self::autoRelease($status->autoRelease),
            'Request ID' => $status->requestId,
        ]));

        if ($status->doctor !== null) {
            $doctor = $status->doctor;
            $health = $doctor['issues'] === [] ? ($doctor['collected_at'] === null ? 'not collected yet' : 'healthy') : 'issues';
            $lines = ['Doctor: '.$health.($doctor['collected_at'] === null ? '' : ', collected '.$doctor['collected_at'])];
            if ($doctor['last_run_ms'] !== null) {
                $lines[0] .= ', last run '.$doctor['last_run_ms'].' ms';
            }
            foreach ($doctor['issues'] as $issue) {
                $since = $issue['since'] === null ? 'unknown' : substr($issue['since'], 0, 10);
                $newest = $issue['newest_since'] === $issue['since'] ? '' : ', newest since '.substr($issue['newest_since'] ?? '', 0, 10);
                $resource = $issue['resource'];
                $suffix = $resource === null ? '' : ' ('.$resource['type'].' '.($resource['id'] ?? 'unknown').')';
                $lines[] = "  {$issue['node_name']} {$issue['code']} ×{$issue['count']} since {$since}{$newest}{$suffix}";
            }
            if ($doctor['hidden_nodes'] > 0) {
                $noun = $doctor['hidden_nodes'] === 1 ? 'Node has' : 'Nodes have';
                $lines[] = "{$doctor['hidden_nodes']} more {$noun} issues you cannot see";
            }
            ConsoleWriter::write($this->output, implode("\n", $lines)."\n");
        }

        if ($status->reconcile !== null) {
            $reconcile = $status->reconcile;
            $line = 'Reconcile: '.($reconcile['finished_at'] === null ? 'not run yet' : 'finished '.$reconcile['finished_at']);
            if ($reconcile['last_pass_ms'] !== null) {
                $line .= ', last pass '.$reconcile['last_pass_ms'].' ms';
            }
            ConsoleWriter::write($this->output, $line.', unsettled '.$reconcile['unsettled_count']."\n");
        }

        return self::SUCCESS;
    }

    /** @param array{enabled: bool, paused: bool, last_checked_at: string|null, last_result: string|null}|null $state */
    private static function autoRelease(?array $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $switch = $state['paused'] ? 'paused' : ($state['enabled'] ? 'enabled' : 'disabled');

        return $state['last_checked_at'] === null
            ? $switch
            : "{$switch}, last check {$state['last_checked_at']}: {$state['last_result']}";
    }
}
