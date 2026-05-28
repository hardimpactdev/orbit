<?php

declare(strict_types=1);

use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventDecoder;
use Orbit\Core\Progress\ProgressEventDecodingFailed;
use Orbit\Core\Progress\ProgressEventEncoder;
use Orbit\Core\Progress\ProgressEventType;

describe(ProgressEventEncoder::class, function (): void {
    it('encodes a step event as a single SSE frame', function (): void {
        $encoder = new ProgressEventEncoder;

        $frame = $encoder->encode(new ProgressEvent(
            type: ProgressEventType::Step,
            payload: ['title' => 'Provisioning runtime', 'percent' => 25],
        ));

        expect($frame)->toBe(
            "event: step\ndata: {\"title\":\"Provisioning runtime\",\"percent\":25}\n\n"
        );
    });

    it('encodes a complete event with an empty payload', function (): void {
        $encoder = new ProgressEventEncoder;

        $frame = $encoder->encode(new ProgressEvent(type: ProgressEventType::Complete));

        expect($frame)->toBe("event: complete\ndata: []\n\n");
    });

    it('encodes every documented event type', function (): void {
        $encoder = new ProgressEventEncoder;

        foreach ([
            ProgressEventType::Tree,
            ProgressEventType::Step,
            ProgressEventType::Complete,
            ProgressEventType::Error,
        ] as $type) {
            $frame = $encoder->encode(new ProgressEvent(type: $type));
            expect($frame)->toStartWith("event: {$type->value}\n");
        }
    });
});

describe(ProgressEventDecoder::class, function (): void {
    it('decodes a well-formed step frame', function (): void {
        $decoder = new ProgressEventDecoder;

        $event = $decoder->decode("event: step\ndata: {\"title\":\"Provisioning runtime\",\"percent\":25}\n");

        expect($event)->not->toBeNull()
            ->and($event->type)->toBe(ProgressEventType::Step)
            ->and($event->payload)->toBe(['title' => 'Provisioning runtime', 'percent' => 25]);
    });

    it('decodes a complete frame with an empty payload object', function (): void {
        $decoder = new ProgressEventDecoder;

        $event = $decoder->decode("event: complete\ndata: {}\n");

        expect($event?->type)->toBe(ProgressEventType::Complete)
            ->and($event?->payload)->toBe([]);
    });

    it('returns null for an SSE comment keepalive', function (): void {
        $decoder = new ProgressEventDecoder;

        $event = $decoder->decode(": heartbeat\n");

        expect($event)->toBeNull();
    });

    it('returns null for multiple stacked comment keepalives', function (): void {
        $decoder = new ProgressEventDecoder;

        $event = $decoder->decode(": heartbeat\n: keepalive\n");

        expect($event)->toBeNull();
    });

    it('skips interleaved comment lines inside a real frame', function (): void {
        $decoder = new ProgressEventDecoder;

        $event = $decoder->decode(": keepalive\nevent: step\n: another comment\ndata: {\"percent\":75}\n");

        expect($event?->type)->toBe(ProgressEventType::Step)
            ->and($event?->payload)->toBe(['percent' => 75]);
    });

    it('concatenates multi-line data: payload with newlines', function (): void {
        $decoder = new ProgressEventDecoder;

        $event = $decoder->decode("event: tree\ndata: {\"items\":[\ndata: 1,2,3\ndata: ]}\n");

        expect($event?->type)->toBe(ProgressEventType::Tree)
            ->and($event?->payload)->toBe(['items' => [1, 2, 3]]);
    });

    it('throws when the event type is unknown', function (): void {
        $decoder = new ProgressEventDecoder;

        expect(fn () => $decoder->decode("event: unknown\ndata: {}\n"))
            ->toThrow(ProgressEventDecodingFailed::class);
    });

    it('throws when the data payload is invalid JSON', function (): void {
        $decoder = new ProgressEventDecoder;

        expect(fn () => $decoder->decode("event: step\ndata: not-json\n"))
            ->toThrow(ProgressEventDecodingFailed::class);
    });
});
