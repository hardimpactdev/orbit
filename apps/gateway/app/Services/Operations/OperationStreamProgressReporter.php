<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\ProgressReporter;
use App\Models\OperationRun;
use Orbit\Core\Operations\OperationStreamFrameType;

final readonly class OperationStreamProgressReporter implements ProgressReporter
{
    private OperationStreamFramePublisher $publisher;

    public function __construct(
        OperationRun $operationRun,
        OperationRunRecorder $operationRuns,
        OperationStreamFrameBroadcaster $broadcaster,
    ) {
        $this->publisher = new OperationStreamFramePublisher($operationRun, $operationRuns, $broadcaster);
    }

    public function tree(string $title, array $steps): void
    {
        $this->publisher->publish(OperationStreamFrameType::Tree, [
            'title' => $title,
            'steps' => $steps,
        ]);
    }

    public function stepStart(string $key): void
    {
        $this->stepProgress($key, 'start');
    }

    public function stepProgress(string $key, string $status, ?string $message = null): void
    {
        $payload = [
            'key' => $key,
            'status' => $status,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $this->publisher->publish(OperationStreamFrameType::Step, $payload);
    }

    public function stepDone(string $key, ?string $message = null): void
    {
        $this->stepProgress($key, 'done', $message);
    }

    public function stepFail(string $key, string $message): void
    {
        $this->stepProgress($key, 'fail', $message);
    }

    public function stepSkip(string $key, ?string $message = null): void
    {
        $this->stepProgress($key, 'skip', $message);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function complete(int $exitCode, array $data): void
    {
        $this->publisher->publish(OperationStreamFrameType::Complete, [
            'exit_code' => $exitCode,
            'data' => $data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function error(string $message, int $exitCode, array $data): void
    {
        $this->publisher->publish(OperationStreamFrameType::Error, [
            'exit_code' => $exitCode,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
