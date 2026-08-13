<?php

declare(strict_types=1);

use Orbit\Core\Operations\DurableOperationStreamFrame;
use Orbit\Core\Operations\OperationStreamFrameCursor;
use Orbit\Core\Operations\OperationStreamFrameDraft;
use Orbit\Core\Operations\OperationStreamFrameEvents;
use Orbit\Core\Operations\OperationStreamFrameSource;
use Orbit\Core\Operations\OperationStreamFrameType;

it('defines the two existing frame type families', function (): void {
    expect(OperationStreamFrameType::gatewayValues())
        ->toBe(['tree', 'step', 'complete', 'error'])
        ->and(OperationStreamFrameType::nodeValues())
        ->toBe(['stdout', 'stderr', 'status', 'control', 'error', 'terminal']);
});

it('keeps the two existing transport event names distinct', function (): void {
    expect(OperationStreamFrameEvents::Live)
        ->toBe('operation.stream.frame')
        ->and(OperationStreamFrameEvents::Journal)
        ->toBe('operation_stream.frame');
});

it('promotes a node draft to the exact durable wire shape', function (): void {
    $draftArray = operation_stream_fixture('node-draft-frame.json');
    $expected = operation_stream_fixture('node-durable-frame.json');

    $draft = OperationStreamFrameDraft::forNode(
        operationUuid: $draftArray['operation_uuid'],
        channel: $draftArray['channel'],
        sequence: $draftArray['sequence'],
        emittedAt: $draftArray['emitted_at'],
        type: OperationStreamFrameType::Stdout,
        payload: $draftArray['payload'],
    );

    $frame = DurableOperationStreamFrame::promote(
        draft: $draft,
        source: OperationStreamFrameSource::node(42, 'app-dev-1'),
        cursor: new OperationStreamFrameCursor('run-1', 11, 101),
    );

    expect($draft->toArray())
        ->toBe($draftArray)
        ->and($frame->toArray())
        ->toBe($expected)
        ->and(DurableOperationStreamFrame::fromArray($expected)->toArray())
        ->toBe($expected);
});

it('keeps the exact gateway wire shape', function (): void {
    $expected = operation_stream_fixture('gateway-durable-frame.json');

    $frame = DurableOperationStreamFrame::fromArray($expected);

    expect($frame->toArray())
        ->toBe($expected)
        ->and($frame->source()->toArray())
        ->toBe(['source' => 'gateway'])
        ->and($frame->draft()->type)
        ->toBe(OperationStreamFrameType::Step);
});

it('uses the operation-local event sequence as the resume cursor', function (): void {
    $cursor = new OperationStreamFrameCursor('run-1', 11, 101);

    expect($cursor->resumeSequence())->toBe(11);
});

it('rejects a cursor for another operation', function (): void {
    $draft = OperationStreamFrameDraft::fromArray(
        operation_stream_fixture('node-draft-frame.json'),
    );

    expect(fn (): DurableOperationStreamFrame => DurableOperationStreamFrame::promote(
        $draft,
        OperationStreamFrameSource::node(42, 'app-dev-1'),
        new OperationStreamFrameCursor('another-run', 11, 101),
    ))
        ->toThrow(InvalidArgumentException::class, 'Cursor operation_uuid must match the frame operation_uuid.');
});

it('rejects non-positive publisher sequences', function (int $sequence): void {
    $frame = operation_stream_fixture('node-draft-frame.json');
    $frame['sequence'] = $sequence;

    expect(fn (): OperationStreamFrameDraft => OperationStreamFrameDraft::fromArray($frame))
        ->toThrow(InvalidArgumentException::class, 'Frame sequence must be a positive integer.');
})->with([0, -1]);

it('rejects non-positive cursor values', function (int $eventSequence, int $eventId): void {
    expect(fn (): OperationStreamFrameCursor => new OperationStreamFrameCursor(
        'run-1',
        $eventSequence,
        $eventId,
    ))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero sequence' => [0, 1],
    'negative sequence' => [-1, 1],
    'zero event id' => [1, 0],
    'negative event id' => [1, -1],
]);

it('rejects both source representations at once', function (): void {
    expect(fn (): OperationStreamFrameSource => OperationStreamFrameSource::fromArray([
        'source' => 'gateway',
        'source_node' => ['id' => 42, 'name' => 'app-dev-1'],
    ]))
        ->toThrow(
            InvalidArgumentException::class,
            'Frame source must contain exactly one of source or source_node.',
        );
});

it('rejects unknown frame types', function (): void {
    $frame = operation_stream_fixture('node-draft-frame.json');
    $frame['type'] = 'unknown';

    expect(fn (): OperationStreamFrameDraft => OperationStreamFrameDraft::fromArray($frame))
        ->toThrow(InvalidArgumentException::class, 'Frame type is invalid.');
});

it('rejects a node-only type from a gateway producer', function (): void {
    $frame = operation_stream_fixture('node-draft-frame.json');

    expect(fn (): OperationStreamFrameDraft => OperationStreamFrameDraft::forGateway(
        operationUuid: $frame['operation_uuid'],
        channel: $frame['channel'],
        sequence: $frame['sequence'],
        emittedAt: $frame['emitted_at'],
        type: OperationStreamFrameType::Stdout,
        payload: $frame['payload'],
    ))
        ->toThrow(InvalidArgumentException::class, 'Frame type [stdout] is not valid for a gateway producer.');
});

/**
 * @return array<string, mixed>
 */
function operation_stream_fixture(string $name): array
{
    $contents = file_get_contents(
        __DIR__.'/../Fixtures/Operations/'.$name,
    );

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read operation stream fixture [{$name}].");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Operation stream fixture [{$name}] must be an object.");
    }

    return $decoded;
}
