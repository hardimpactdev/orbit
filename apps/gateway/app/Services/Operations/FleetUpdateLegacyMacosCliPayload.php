<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Services\NodeCommandTransport\NodeTransportPreference;
use JsonException;
use RuntimeException;

final readonly class FleetUpdateLegacyMacosCliPayload
{
    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, transport?: NodeTransportPreference|string, bind_application_key?: bool, bind_input?: bool, ssh_bootstrap_binary?: array{url: string, sha256: string}, ssh_bootstrap_input_file?: array{path: string, sha256: string}}  $transportOptions
     * @return array<int|string, mixed>
     */
    public function commandOptionsFor(array $commandOptions, array $transportOptions): array
    {
        return [
            ...$commandOptions,
            'payload-sha256' => hash('sha256', $transportOptions['input']),
        ];
    }

    /**
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, transport?: NodeTransportPreference|string, bind_application_key?: bool, bind_input?: bool, ssh_bootstrap_binary?: array{url: string, sha256: string}, ssh_bootstrap_input_file?: array{path: string, sha256: string}}  $transportOptions
     * @return array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, transport?: NodeTransportPreference|string, bind_application_key?: bool, bind_input?: bool, ssh_bootstrap_binary?: array{url: string, sha256: string}, ssh_bootstrap_input_file?: array{path: string, sha256: string}}
     *
     * @throws JsonException
     */
    public function transportOptionsFor(array $transportOptions): array
    {
        /** @var mixed $payload */
        $payload = json_decode($transportOptions['input'], associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new RuntimeException('Fleet update CLI install payload must be an object.');
        }

        $payload['bin_path'] = '/usr/local/bin/orbit';
        $payload['shared_binary_path'] = null;

        $input = json_encode($payload, JSON_THROW_ON_ERROR);
        $transportOptions['input'] = $input;

        return $this->withUpdatedBootstrapPayloadHash($transportOptions, $input);
    }

    /**
     * @param  array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, transport?: NodeTransportPreference|string, bind_application_key?: bool, bind_input?: bool, ssh_bootstrap_binary?: array{url: string, sha256: string}, ssh_bootstrap_input_file?: array{path: string, sha256: string}}  $transportOptions
     * @return array{timeout: int, input: string, metadata: array<string, string>, cwd?: string, environment?: array<string, string>, transport?: NodeTransportPreference|string, bind_application_key?: bool, bind_input?: bool, ssh_bootstrap_binary?: array{url: string, sha256: string}, ssh_bootstrap_input_file?: array{path: string, sha256: string}}
     */
    private function withUpdatedBootstrapPayloadHash(array $transportOptions, string $input): array
    {
        $bootstrapInputFile = $transportOptions['ssh_bootstrap_input_file'] ?? null;

        if (! is_array($bootstrapInputFile)) {
            return $transportOptions;
        }

        $transportOptions['ssh_bootstrap_input_file'] = [
            'path' => $bootstrapInputFile['path'],
            'sha256' => hash('sha256', $input),
        ];

        return $transportOptions;
    }
}
