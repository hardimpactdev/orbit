<?php

declare(strict_types=1);

use App\Support\Streaming\StreamFlusher;

describe('StreamFlusher', function (): void {
    it('flushes streamed output for FrankenPHP', function (): void {
        ob_start();
        ob_start();

        echo 'streamed chunk';

        new StreamFlusher('frankenphp')->flush();

        $innerBuffer = ob_get_contents();
        ob_end_clean();
        $outerBuffer = ob_get_clean();

        expect($innerBuffer)
            ->toBeEmpty()
            ->and($outerBuffer)
            ->toBe('streamed chunk');
    });

    it('leaves output buffered for non-streaming SAPIs', function (): void {
        ob_start();
        ob_start();

        echo 'streamed chunk';

        new StreamFlusher('cli')->flush();

        $innerBuffer = ob_get_contents();
        ob_end_clean();
        $outerBuffer = ob_get_clean();

        expect($innerBuffer)
            ->toBe('streamed chunk')
            ->and($outerBuffer)
            ->toBeEmpty();
    });
});
