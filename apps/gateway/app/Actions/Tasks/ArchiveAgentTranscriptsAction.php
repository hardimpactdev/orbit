<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentObservation;
use App\Models\AgentThread;
use Illuminate\Support\Facades\Storage;

/** Stores and reads redacted turn transcripts on the Gateway. */
final readonly class ArchiveAgentTranscriptsAction
{
    public const string Directory = 'task-transcripts';

    /** @param list<array<mixed>> $entries Normalized, redacted entries recorded by Swamp. */
    public function record(AgentThread $thread, array $entries): void
    {
        $normalized = [];
        foreach ($entries as $entry) {
            if (in_array($entry['kind'] ?? null, ['message', 'activity'], true)
                && is_string($entry['id'] ?? null) && $entry['id'] !== ''
                && is_string($entry['label'] ?? null) && is_string($entry['text'] ?? null) && is_string($entry['at'] ?? null)) {
                $normalized[$entry['id']] = ['id' => $entry['id'], 'kind' => $entry['kind'], 'label' => $entry['label'], 'text' => $entry['text'], 'at' => $entry['at']];
            }
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
