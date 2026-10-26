<?php

declare(strict_types=1);

use Tests\Support\HttpFixtureServer;

require __DIR__.'/../../Support/HttpFixtureServer.php';

[$inputPath, $outputPath] = array_slice($argv, 1);
$requests = [];
$listCount = 0;
(new HttpFixtureServer)->serve(static function (string $method, string $path, array $headers, string $input) use ($inputPath, $outputPath, &$requests, &$listCount): array {
    $state = json_decode(file_get_contents($inputPath), true, flags: JSON_THROW_ON_ERROR);
    $mode = $state['mode'] ?? '';
    parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
    $key = explode('/', rawurldecode(parse_url($path, PHP_URL_PATH)), 3)[2] ?? '';
    $listing = $method === 'GET' && array_key_exists('list-type', $query);
    $status = 200;
    $body = '';
    if ($method === 'GET') {
        if ($listing) {
            $listCount++;
            if ($mode === 'database-change') {
                (new PDO('sqlite:'.dirname($inputPath).'/database.sqlite'))->exec('UPDATE project_document_entries SET revision = revision + 1');
            }
            $xml = new SimpleXMLElement('<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>');
            $keys = array_keys($state['objects']);
            sort($keys, SORT_STRING);
            if ($mode === 'changing-list' && $listCount > 1) {
                $keys[] = 'new-object';
            }
            $offset = (int) preg_replace('/^page\+%2F-/', '', $query['continuation-token'] ?? '0');
            $encoded = ($query['encoding-type'] ?? null) === 'url';
            if ($encoded && $mode !== 'encoding-missing' && ! ($mode === 'encoding-missing-page' && $offset > 0)) {
                $xml->addChild('EncodingType', $mode === 'encoding-unsupported' ? 'unsupported' : 'url');
            }
            $truncated = $offset + 2 < count($keys);
            $xml->addChild('IsTruncated', $truncated ? 'true' : 'false');
            if ($truncated) {
                $token = (in_array($mode, ['opaque-tokens', 'literal-plus'], true) ? 'page+%2F-' : '').($offset + 2);
                $xml->addChild('NextContinuationToken', $mode === 'repeated-token' ? '0' : $token);
            }
            foreach (array_slice($keys, $offset, 2) as $name) {
                $object = $state['objects'][$name] ?? ['body' => ''];
                $row = $xml->addChild('Contents');
                $keyText = $encoded ? rawurlencode($name) : $name;
                if ($encoded && $mode === 'literal-plus') {
                    $keyText = str_replace('%2B', '+', $keyText);
                }
                $row->addChild('Key', htmlspecialchars($mode === 'encoding-malformed' ? 'bad%GG' : $keyText, ENT_XML1));
                $row->addChild('Size', (string) strlen(base64_decode($object['body'])));
                $row->addChild('LastModified', '2026-10-01T00:00:00Z');
                $row->addChild('ETag', $mode === 'changing-list' ? (string) $listCount : ($object['etag'] ?? 'stable-marker'));
            }
            $body = $mode === 'malformed-page' ? '<ListBucketResult><IsTruncated>true</IsTruncated></ListBucketResult>' : $xml->asXML();
        } elseif (! array_key_exists($key, $state['objects'])) {
            $status = 404;
            $body = '<Error><Code>NoSuchKey</Code></Error>';
        } else {
            $body = base64_decode($state['objects'][$key]['body']);
        }
        if ($mode === ($listing ? 'list-500' : 'get-500')) {
            $status = 500;
            $body = '<Error><Code>InternalError</Code><Message>fixture-secret</Message></Error>';
        }
    } elseif ($method === 'PUT') {
        $state['objects'][$key] = ['body' => base64_encode($input)];
        file_put_contents($inputPath, json_encode($state, JSON_THROW_ON_ERROR));
    } elseif ($method === 'DELETE') {
        if ($mode === 'delete-500') {
            $status = 500;
            $body = '<Error><Code>InternalError</Code><Message>fixture-secret</Message></Error>';
        } elseif ($mode === 'delete-redirect') {
            $status = 307;
        } else {
            unset($state['objects'][$key]);
            file_put_contents($inputPath, json_encode($state, JSON_THROW_ON_ERROR));
            $status = 204;
        }
    }
    $requests[] = ['method' => $method, 'path' => $path, 'listing' => $listing, 'status' => $status];
    file_put_contents($outputPath, json_encode($requests, JSON_THROW_ON_ERROR));
    chmod($outputPath, 0600);
    $responseHeaders = ['Content-Type' => $listing || $status !== 200 ? 'application/xml' : 'application/octet-stream'];
    if ($status === 307) {
        $responseHeaders['Location'] = '/fixture-bucket/redirect-target';
    }

    return [$status, $body, $responseHeaders, 4096, false];
});
