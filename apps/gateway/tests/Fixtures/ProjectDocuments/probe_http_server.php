<?php

declare(strict_types=1);

use Tests\Support\HttpFixtureServer;

require __DIR__.'/../../Support/HttpFixtureServer.php';

[$mode, $statePath] = array_slice($argv, 1);
$objects = $requests = [];
(new HttpFixtureServer)->serve(static function (string $method, string $path, array $headers, string $input) use ($mode, $statePath, &$objects, &$requests): array {
    $status = 200;
    $body = '';
    $responseHeaders = [];
    if ($method === 'PUT') {
        $objects[$path] = $input;
    } elseif ($method === 'GET') {
        $body = $objects[$path] ?? str_repeat('a', 32);
        if (str_starts_with($mode, 'get-')) {
            $status = (int) explode('-', $mode)[1];
            $code = str_ends_with($mode, '-bucket') ? 'NoSuchBucket' : [403 => 'AccessDenied', 404 => 'NoSuchKey', 500 => 'InternalError'][$status];
            $body = '<Error><Code>'.$code.'</Code><Message>fixture-access fixture-secret</Message></Error>';
            if (str_ends_with($mode, '-oversized')) {
                $body .= str_repeat('a', 1024 * 1024);
            }
        }
        if ($mode === 'oversized') {
            $body = str_repeat('a', 1024 * 1024);
        } elseif ($mode === 'trailing') {
            $body .= 'b';
        }
        if ($status !== 200) {
            $responseHeaders['Content-Type'] = 'application/xml';
        }
    } elseif ($method === 'DELETE') {
        $status = 204;
        if ($mode === 'oversized') {
            $status = 500;
            $body = str_repeat('a', 1024 * 1024);
        } elseif (str_starts_with($mode, 'delete-')) {
            $status = (int) substr($mode, 7);
            $code = [403 => 'AccessDenied', 404 => 'NoSuchKey', 500 => 'InternalError', 302 => 'Redirect'][$status];
            $body = '<Error><Code>'.$code.'</Code><Message>fixture-access fixture-secret</Message></Error>';
        }
        if ($status === 204) {
            unset($objects[$path]);
        }
        if ($status === 302) {
            $responseHeaders['Location'] = '/redirect-target';
        }
        $responseHeaders['Content-Type'] = 'application/xml';
    }
    $requests[] = ['method' => $method, 'key' => $path, 'status' => $status, 'acl' => $headers['x-amz-acl'] ?? null,
        'authorized' => str_starts_with($headers['authorization'] ?? '', 'AWS4-HMAC-SHA256 ')];
    file_put_contents($statePath, json_encode(['requests' => $requests, 'object_count' => count($objects)], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);

    return [$status, $body, $responseHeaders, $mode === 'short' ? 8 : 4096, $method === 'GET' && in_array($mode, ['body-stall', 'body-timeout'], true)];
}, $mode === 'tls-stall');
