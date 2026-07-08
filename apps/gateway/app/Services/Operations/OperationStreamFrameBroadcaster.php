<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OperationStreamFrameBroadcaster
{
    /**
     * @param  array<string, mixed>  $frame
     */
    public function broadcast(string $channel, array $frame): void
    {
        $appId = Config::string('orbit.operations.reverb.app_id');
        $appKey = Config::string('orbit.operations.reverb.app_key');
        $appSecret = Config::string('orbit.operations.reverb.app_secret');
        $host = Config::string('orbit.operations.reverb.host');
        $port = $this->integerConfig('orbit.operations.reverb.port', 8080);
        $scheme = Config::string('orbit.operations.reverb.scheme');
        $timeoutSeconds = $this->integerConfig('orbit.operations.reverb.timeout_seconds', 2);

        $body = [
            'name' => 'operation.stream.frame',
            'channel' => $channel,
            'data' => json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = "/apps/{$appId}/events";
        $query = [
            'auth_key' => $appKey,
            'auth_timestamp' => (string) Carbon::now()->timestamp,
            'auth_version' => '1.0',
            'body_md5' => md5($encodedBody),
        ];

        ksort($query);

        $signaturePayload = "POST\n{$path}\n"
        .http_build_query(
            data: $query,
            numeric_prefix: '',
            arg_separator: '&',
            encoding_type: PHP_QUERY_RFC3986,
        );
        $query['auth_signature'] = hash_hmac('sha256', $signaturePayload, $appSecret);

        $url = "{$scheme}://{$host}:{$port}{$path}?"
        .http_build_query(
            data: $query,
            numeric_prefix: '',
            arg_separator: '&',
            encoding_type: PHP_QUERY_RFC3986,
        );

        $response = Http::timeout($timeoutSeconds)
            ->asJson()
            ->post($url, $body);

        if ($response->failed()) {
            throw new RuntimeException('Failed to publish operation stream frame to the operations Reverb service.');
        }
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = Config::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
