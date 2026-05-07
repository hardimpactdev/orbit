<?php

declare(strict_types=1);

namespace App\Support\Streaming;

use App\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ProgressEventStreamResponseFactory
{
    /**
     * @param  callable(ProgressEventStreamEmitter): void  $streamer
     */
    public function make(callable $streamer): StreamedResponse
    {
        return new StreamedResponse(function () use ($streamer): void {
            if (PHP_SAPI === 'fpm-fcgi') {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            $emitter = new ProgressEventStreamEmitter;

            app()->instance(ProgressReporter::class, new SseProgressReporter($emitter));

            try {
                $streamer($emitter);
            } catch (Throwable $e) {
                Log::error('Progress stream crashed: '.$e->getMessage(), ['exception' => $e]);
                $emitter->error($e->getMessage());
            } finally {
                app()->instance(ProgressReporter::class, new NullProgressReporter);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
