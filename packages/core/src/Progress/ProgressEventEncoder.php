<?php

declare(strict_types=1);

namespace Orbit\Core\Progress;

use JsonException;

/**
 * Encodes ProgressEvent values into Server-Sent Events frames suitable for emission by a
 * Laravel streaming response. Framework-neutral: returns a raw string, never writes to any
 * HTTP/Console object.
 */
final class ProgressEventEncoder
{
    public function encode(ProgressEvent $event): string
    {
        try {
            $data = json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ProgressEventEncodingFailed(
                "Failed to encode progress event payload: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return "event: {$event->type->value}\ndata: {$data}\n\n";
    }
}
