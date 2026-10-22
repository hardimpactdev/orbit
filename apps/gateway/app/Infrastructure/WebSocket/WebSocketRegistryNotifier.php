<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Models\WebSocketRuntime;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Revision hints carry no app secrets. Reconciliation recovers any failed delivery. */
final readonly class WebSocketRegistryNotifier
{
    public function notify(string $epoch, int $revision): void
    {
        $runtimes = WebSocketRuntime::query()->where('required', true)->with('node')->get();
        if ($runtimes->isEmpty()) {
            return;
        }
        try {
            Http::pool(static function (Pool $pool) use ($runtimes, $epoch, $revision): array {
                $requests = [];
                foreach ($runtimes as $runtime) {
                    $address = $runtime->node->wireguard_ip;
                    if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                        continue;
                    }
                    $requests[] = $pool->as($runtime->id)->withToken($runtime->notification_token)
                        ->connectTimeout(1)->timeout(2)->withoutRedirecting()
                        ->withOptions(['curl' => [CURLOPT_RESOLVE => ['reverb.orbit:443:'.$address]]])
                        ->post('https://reverb.orbit/internal/orbit/registry-changed', ['epoch' => $epoch, 'revision' => $revision]);
                }

                return $requests;
            });
        } catch (Throwable) {
            // Do not log request exceptions: they may contain protected headers.
        }
    }
}
