<?php

declare(strict_types=1);

namespace App\Support\Streaming;

final readonly class ProgressEventStreamEmitter
{
    private const int BUFFERING_PRELUDE_BYTES = 1;

    private StreamFlusher $flusher;

    public function __construct(
        private string $sapi = PHP_SAPI,
    ) {
        $this->flusher = new StreamFlusher($this->sapi);
    }

    public function bufferingPrelude(): void
    {
        echo ': '.str_repeat(' ', self::BUFFERING_PRELUDE_BYTES)."\n\n";
        $this->flush();
    }

    /**
     * @param  list<array{key: string, label: string, doneLabel?: string}>  $steps
     */
    public function tree(string $title, array $steps): void
    {
        $this->emit('tree', [
            'title' => $title,
            'steps' => $steps,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function stepEvent(string $key, string $status, ?string $message = null, array $extra = []): void
    {
        $payload = [
            'key' => $key,
            'status' => $status,
            ...$extra,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $this->emit('step', $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function complete(int $exitCode, array $data = []): void
    {
        $this->emit('complete', [
            'exit_code' => $exitCode,
            'data' => $data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function error(string $message, int $exitCode = 1, array $data = []): void
    {
        $this->emit('error', [
            'exit_code' => $exitCode,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function heartbeat(): void
    {
        echo ": heartbeat\n\n";
        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function event(string $event, array $payload, ?int $id = null): void
    {
        $this->emit($event, $payload, $id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $event, array $payload, ?int $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        echo "event: {$event}\n";
        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        $this->flusher->flush();
    }
}
