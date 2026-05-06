<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Tools;

final readonly class ToolLogsResponse
{
    /**
     * @param  array<string, mixed>  $logs
     */
    public function __construct(
        public array $logs,
    ) {}
}
