<?php

declare(strict_types=1);

namespace App\Support\Streaming;

use App\Contracts\ProgressReporter;
use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class ProgressEventStreamResponseFactory
{
    /**
     * @param null|Closure(ProgressEventStreamEmitter): ProgressReporter $reporterFactory
     */
    public function __construct(
        private string $sapi = PHP_SAPI,
        private ?Closure $reporterFactory = null,
    ) {}

    /**
     * @param  callable(ProgressEventStreamEmitter): void  $streamer
     */
    public function make(callable $streamer): StreamedResponse
    {
        return new StreamedResponse(
            function () use ($streamer): void {
                $emitter = new ProgressEventStreamEmitter($this->sapi);

                $reporter = $this->reporterFactory instanceof Closure
                    ? ($this->reporterFactory)($emitter)
                    : new SseProgressReporter($emitter);

                app()->instance(ProgressReporter::class, $reporter);
                $emitter->bufferingPrelude();

                try {
                    $streamer($emitter);
                } catch (Throwable $e) {
                    Log::error('Progress stream crashed: '.$e->getMessage(), ['exception' => $e]);
                    $emitter->error($e->getMessage());
                } finally {
                    app()->instance(ProgressReporter::class, new NullProgressReporter);
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }
}
