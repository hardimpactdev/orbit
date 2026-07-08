<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use Illuminate\Http\Client\Response;

final class NodeAgentPushStreamReader
{
    public const string PROCESS_STREAM_V1_CONTENT_TYPE = 'application/vnd.orbit.process-stream.v1';

    /**
     * @param  callable(string): void  $onOutput
     */
    public function read(Response $response, callable $onOutput): NodeAgentPushStreamResult
    {
        if (! $this->isProcessStreamV1($this->contentType($response))) {
            return $this->readLegacyPassthrough($response, $onOutput);
        }

        $body = $response->toPsrResponse()->getBody();
        $frames = new NodeAgentPushStreamFrameBuffer;
        $collector = new NodeAgentPushStreamFrameCollector;

        try {
            while (! $body->eof()) {
                if (! $body->eof()) {
                    $chunk = $body->read(8192);

                    if ($chunk === '') {
                        usleep(10_000);

                        continue;
                    }

                    $frames->append($chunk);
                }

                foreach ($frames->drainFrames() as $frame) {
                    $collector->accept($frame, $onOutput);
                }
            }

            $frames->assertEmpty();
        } finally {
            $response->close();
        }

        return $collector->result();
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    private function readLegacyPassthrough(Response $response, callable $onOutput): NodeAgentPushStreamResult
    {
        $body = $response->toPsrResponse()->getBody();

        try {
            while (! $body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    usleep(10_000);

                    continue;
                }

                $onOutput($chunk);
            }
        } finally {
            $response->close();
        }

        return new NodeAgentPushStreamResult(
            exitCode: 0,
            agentError: null,
            legacyPassthrough: true,
        );
    }

    private function contentType(Response $response): ?string
    {
        $contentType = $response->header('Content-Type');

        if (! is_string($contentType) || trim($contentType) === '') {
            return null;
        }

        return trim(explode(';', $contentType, 2)[0]);
    }

    private function isProcessStreamV1(?string $contentType): bool
    {
        return strtolower((string) $contentType) === self::PROCESS_STREAM_V1_CONTENT_TYPE;
    }
}
