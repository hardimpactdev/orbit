<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\AgentThread;
use App\Models\Task;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class StreamAgentThreadAction
{
    public function __construct(private ReadAgentThreadAction $read) {}

    public function execute(Task $group, AgentThread $session, ?string $afterSequence): StreamedResponse
    {
        $data = $this->read->execute($group, $session, $afterSequence);

        return response()->stream(static function () use ($data): \Generator {
            yield "retry: 3000\n\n";
            yield "event: agent\ndata: ".json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }
}
