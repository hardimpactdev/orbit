<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use Orbit\Core\Progress\ProgressEventType;

final class UpdateAllCommand extends GatewayCommand
{
    use StreamsGatewayProgress;

    #[\Override]
    protected $signature = 'update:all {--json : Output JSON}';

    #[\Override]
    protected $description = 'Update every managed Orbit installation through the gateway.';

    public function handle(): int
    {
        return $this->streamProgress(
            '/api/update/all',
            [],
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }
}
