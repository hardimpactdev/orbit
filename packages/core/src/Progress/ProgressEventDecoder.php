<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

use JsonException;

/**
 * Decodes Server-Sent Events frames into ProgressEvent values. SSE comment lines starting
 * with ':' are transport keepalives and are silently skipped. Multi-line `data:` payloads
 * are concatenated with newlines per the SSE spec. Framework-neutral.
 */
final class ProgressEventDecoder
{
    /**
     * Decode a complete SSE frame (separated from neighboring frames by a blank line) into a
     * ProgressEvent. Returns null when the frame is a comment-only keepalive (e.g. ": heartbeat").
     */
    public function decode(string $frame): ?ProgressEvent
    {
        $eventName = null;
        $dataLines = [];
        $hasNonCommentLine = false;

        foreach (explode("\n", $frame) as $line) {
            $line = rtrim($line, "\r");

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, ':')) {
                // SSE comment keepalive — skip.
                continue;
            }

            $hasNonCommentLine = true;

            if (str_starts_with($line, 'event:')) {
                $eventName = trim(substr($line, 6));

                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }

        if (! $hasNonCommentLine) {
            return null;
        }

        if ($eventName === null || $eventName === '') {
            throw new ProgressEventDecodingFailed('SSE frame is missing an event: line.');
        }

        $type = ProgressEventType::tryFrom($eventName);

        if (! $type instanceof ProgressEventType) {
            throw new ProgressEventDecodingFailed("Unknown progress event type: {$eventName}.");
        }

        $rawData = implode("\n", $dataLines);

        if ($rawData === '') {
            return new ProgressEvent(type: $type, payload: []);
        }

        try {
            $decoded = json_decode($rawData, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProgressEventDecodingFailed(
                "Failed to decode progress event payload: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new ProgressEventDecodingFailed('Progress event payload must decode to an object.');
        }

        /** @var array<string, mixed> $decoded */
        return new ProgressEvent(type: $type, payload: $decoded);
    }
}
