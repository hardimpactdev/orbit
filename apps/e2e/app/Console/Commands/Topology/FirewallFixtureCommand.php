<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class FirewallFixtureCommand extends E2ECommand
{
    use RecordsEvidence;

    #[\Override]
    protected $signature = 'topology:firewall-fixture {issue} {operation} '.self::WORKTREE_OPTION.self::RECORD_OPTION.' {--json}';

    #[\Override]
    protected $description = 'Control the fixed, lease-scoped TASK-1791 Router LAN acceptance fixture';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $request = $this->request();
            $label = $this->evidenceLabel() ?? throw new InvalidArgumentException('The fixture requires --record=LABEL for every operation.');
            $report = $acquirer->firewallFixture($request, $this->stringArgument('operation'), function (
                string $node, GuestCommand $command, GuestCommandResult $result, DateTimeImmutable $start, DateTimeImmutable $end,
            ) use ($request, $label): void {
                $this->recordEvidence($label, $request, $node, $command->command, $result, $start, $end);
            });
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
