<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\OperationRun;
use App\Services\Operations\OperationEventStreamer;
use App\Services\Operations\OperationProgressEventEmitter;
use App\Services\Operations\OperationStreamOptions;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresPermission('*', servingNode: ServingNode::Gateway)]
final readonly class OperationEventStreamController
{
    public function __invoke(
        Request $request,
        OperationRun $operationRun,
        OperationEventStreamer $streamer,
        OperationProgressEventEmitter $operationEvents,
        ProgressEventStreamResponseFactory $streams,
    ): StreamedResponse {
        $options = OperationStreamOptions::fromRequest($request);

        return $streams->make(function (ProgressEventStreamEmitter $events) use (
            $streamer,
            $operationEvents,
            $operationRun,
            $options,
        ): void {
            foreach ($streamer->follow(
                $operationRun,
                $options->lastSequence,
                $options->pollMicroseconds,
                $options->maxIdlePolls,
            ) as $event) {
                $operationEvents->emit($events, $event);
            }
        });
    }
}
