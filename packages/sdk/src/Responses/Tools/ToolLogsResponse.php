<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Tools;

final readonly class ToolLogsResponse
{
    /**
     * @param  array<string, mixed>  $logs
     */
    public function __construct(
        public array $logs,
        public int $lineCount,
    ) {}
}
