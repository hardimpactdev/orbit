<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverRegistry;
use App\Models\AgentThread;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keeps a copy of each agent transcript on the Gateway before the task VM that holds the sessions is deleted.
 * The copy is the driver's redacted observation, the same snapshot the agent viewer streams. Saving is best
 * effort: a thread that cannot be read is reported and skipped, so an archive never stops workspace removal.
 */
final readonly class ArchiveAgentTranscriptsAction
{
    public const string Directory = 'task-transcripts';

    private const float READ_TIMEOUT = 15.0;

    public function __construct(private AgentDriverRegistry $drivers) {}

    public function execute(int $groupId, int $nodeId): void
    {
        $threads = AgentThread::query()
            ->where('task_group_id', $groupId)
            ->where('node_id', $nodeId)
            ->where('driver', '!=', 't3')
            ->started()
            ->get();
        foreach ($threads as $thread) {
            try {
                $observation = $this->drivers->get($thread->driver)->read($thread, null, self::READ_TIMEOUT)->toArray();
                Storage::disk('local')->put(self::path($thread), json_encode($observation, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @return array<string, mixed>|null the archived observation, or null when the thread has none */
    public function read(AgentThread $thread): ?array
    {
        $json = Storage::disk('local')->get(self::path($thread));
        if (! is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        $observation = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $observation[$key] = $value;
            }
        }

        return $observation;
    }

    public static function path(AgentThread $thread): string
    {
        return self::Directory.'/'.$thread->task_group_id.'/'.$thread->id.'.json';
    }
}
