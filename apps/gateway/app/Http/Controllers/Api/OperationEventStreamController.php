<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
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
        OperationRunRecorder $operationRuns,
        ProgressEventStreamResponseFactory $streams,
    ): StreamedResponse {
        $lastEventId = $this->lastEventId($request);

        return $streams->make(function (ProgressEventStreamEmitter $events) use ($operationRuns, $operationRun, $lastEventId): void {
            foreach ($operationRuns->eventsAfter($operationRun->id, $lastEventId) as $event) {
                $events->event($event->event_type, $event->payload, $event->id);
            }
        });
    }

    private function lastEventId(Request $request): ?int
    {
        $value = $request->headers->get('Last-Event-ID');

        if ($value === null) {
            $queryValue = $request->query('last_event_id');
            $value = is_scalar($queryValue) ? (string) $queryValue : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $lastEventId = (int) $value;

        return $lastEventId > 0 ? $lastEventId : null;
    }
}
