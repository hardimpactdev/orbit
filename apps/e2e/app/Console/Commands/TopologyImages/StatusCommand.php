<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologyImages;

use App\Console\Commands\E2ECommand;
use App\E2E\RoleImages;
use Throwable;

final class StatusCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology-images:status {--json}';

    #[\Override]
    protected $description = 'Report each role image and whether it ran the programs the checkout renders now';

    public function handle(RoleImages $images): int
    {
        try {
            $rows = $images->status();
            $text = array_map(
                static fn (array $row): string => sprintf(
                    '%-20s %-8s %s %s',
                    $row['alias'],
                    $row['state'],
                    $row['fingerprint'] === null ? '-' : substr($row['fingerprint'], 0, 12),
                    $row['created_at'] ?? '',
                ).($row['error'] === null ? '' : ' ('.$row['error'].')'),
                $rows,
            );
            $this->outputJson(['images' => $rows], implode("\n", $text));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
