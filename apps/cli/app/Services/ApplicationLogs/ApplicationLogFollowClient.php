<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Exceptions\GatewayApiException;
use App\Services\GatewayOperationStreamSubscriber;
use RuntimeException;

/**
 * Consumes a started application-log operation stream and writes frames.
 */
final readonly class ApplicationLogFollowClient
{
    public function __construct(
        private GatewayOperationStreamSubscriber $subscriber,
    ) {}

    /**
     * @param  array<string, mixed>  $startResponse  gateway POST envelope for log-stream
     * @param  callable(string): void  $write
     */
    public function consumeStartedStream(array $startResponse, callable $write): void
    {
        $operationRunId = data_get(target: $startResponse, key: 'success.data.operation.uuid');

        if (! is_string($operationRunId) || trim($operationRunId) === '') {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Gateway application log stream start response omitted operation uuid.'),
            );
        }

        $this->subscriber->subscribe(
            trim($operationRunId),
            null,
            function (array $frame) use ($write): void {
                if (! in_array($frame['type'] ?? null, ['stdout', 'stderr'], strict: true)) {
                    return;
                }

                $data = data_get(target: $frame, key: 'payload.data');

                if (is_string($data) && $data !== '') {
                    $write($data);
                }
            },
        );
    }
}
