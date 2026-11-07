<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\FollowsLogs;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Logs\ScheduleLogStreamTarget;
use Orbit\Sdk\Requests\Schedules\ScheduleLogsRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleLogsResponse;

final class ScheduleLogsCommand extends ScheduleUuidCommand
{
    use FollowsLogs;

    #[\Override]
    protected $signature = 'schedule:logs
        {schedule : Schedule UUID}
        {--lines=100 : Number of lines, from 1 to 1000}
        {--follow : Keep printing new lines until Ctrl-C}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Return bounded Schedule logs, or follow new lines.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $scheduleId = $this->scheduleId();
        $lines = filter_var(
            $this->option('lines'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]],
        );

        if ($scheduleId === null) {
            return self::FAILURE;
        }

        if (! is_int($lines)) {
            return $this->renderGatewayFailure(
                'schedule.log_lines_invalid',
                'Log lines must be between 1 and 1000.',
            );
        }

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $connector = $connectors->make($profile);

        if ($this->option('follow') === true) {
            return $this->followLogs($connector, $profile, new ScheduleLogStreamTarget($scheduleId), $lines,
                fn (int $read): string => $this->sendOrThrow($connector, new ScheduleLogsRequest($scheduleId, $read), ScheduleLogsResponse::class)->output);
        }

        $response = $this->sendWithProgress(
            $connector,
            new ScheduleLogsRequest($scheduleId, $lines),
            ScheduleLogsResponse::class,
            ['Read Schedule logs', 'Reading Schedule logs', 'Read Schedule logs'],
        );

        if (! $response instanceof ScheduleLogsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $output = rtrim($response->output, characters: "\r\n");

        if ($output !== '') {
            $this->line($output);
        }

        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
