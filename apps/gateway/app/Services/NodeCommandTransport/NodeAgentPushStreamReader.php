<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use Illuminate\Http\Client\Response;

final class NodeAgentPushStreamReader
{
    /**
     * @param  callable(string): void  $onOutput
     */
    public function read(Response $response, callable $onOutput): void
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
    }
}
