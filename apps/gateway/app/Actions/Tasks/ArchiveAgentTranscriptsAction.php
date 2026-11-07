<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentObservation;
use App\Infrastructure\Tasks\Pi\PiTranscript;
use App\Models\AgentThread;
use Illuminate\Support\Facades\Storage;

/** Stores and reads redacted turn transcripts on the Gateway. */
final readonly class ArchiveAgentTranscriptsAction
{
    public const string Directory = 'task-transcripts';

    /** @param list<array<mixed>> $entries Redacted Pi entries recorded by the turn operation. */
    public function record(AgentThread $thread, array $entries): void
    {
        $transcript = new PiTranscript;
        $normalized = [];
        foreach ($entries as $entry) {
            foreach ($transcript->entries($entry) as $item) {
                $normalized[$item['id']] = $item;
            }
        }
        foreach ($transcript->settle() as $item) {
            $normalized[$item['id']] = $item;
        }
        $observation = new AgentObservation($thread->state, entries: array_values($normalized));
        Storage::disk('local')->put(self::path($thread), json_encode($observation->toArray(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
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
