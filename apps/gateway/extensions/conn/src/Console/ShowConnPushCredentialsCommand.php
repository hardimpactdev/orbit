<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Console;

use Illuminate\Console\Command;
use Orbit\Extensions\Conn\Domain\Push\ConnPushCredentialsStore;

/** Shows whether Apple APNs push credentials are configured, with Key ID and Team ID only. */
final class ShowConnPushCredentialsCommand extends Command
{
    /** @var string */
    #[\Override]
    protected $signature = 'conn:push-credentials:show {--json : Return JSON output}';

    /** @var string */
    #[\Override]
    protected $description = 'Show whether Apple APNs push credentials are configured (Key ID and Team ID only).';

    public function handle(ConnPushCredentialsStore $store): int
    {
        $status = $store->status();

        if ($this->option('json') === true) {
            $this->line(json_encode($status->toArray(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Configured: '.($status->configured ? 'yes' : 'no'));
        $this->line('Key ID: '.($status->keyId ?? 'none'));
        $this->line('Team ID: '.($status->teamId ?? 'none'));

        return self::SUCCESS;
    }
}
