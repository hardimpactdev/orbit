<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\RemoteShell\RemoteShellResult;

final readonly class WireGuardSelfRouteOutput
{
    /** @return array{exit_code?: int, output?: string} */
    public function data(RemoteShellResult $result): array
    {
        /** @var mixed $payload */
        $payload = json_decode($result->stdout, associative: true);

        if (! is_array($payload)) {
            return [];
        }

        /** @var mixed $success */
        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        /** @var mixed $data */
        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        $resultData = [];

        if (is_int($data['exit_code'] ?? null)) {
            $resultData['exit_code'] = $data['exit_code'];
        }

        if (is_string($data['output'] ?? null)) {
            $resultData['output'] = $data['output'];
        }

        return $resultData;
    }

    public function hasLocalRoute(string $output, string $address): bool
    {
        $quotedAddress = preg_quote($address, delimiter: '/');
        $lines = preg_split('/\R/', $output);

        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/\s+/', replacement: ' ', subject: $line));

            if (preg_match("/^local {$quotedAddress}\\b.*\\bdev\\s+\\S+/", $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
