<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Models\Node;
use RuntimeException;

final readonly class RemoteSecretFile
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    /**
     * @template TResult
     *
     * @param  callable(string): TResult  $callback
     * @return TResult
     */
    public function stage(Node $node, string $secret, callable $callback): mixed
    {
        $staged = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:secret-file',
            arguments: ['stage'],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'secret-file-stage',
                ],
                'input' => json_encode([
                    'content_base64' => base64_encode($secret),
                ], JSON_THROW_ON_ERROR),
                'redact_stdout' => true,
                'strict' => false,
                'throw' => false,
            ],
        );

        $data = RemoteShellSuccessData::fromJsonEnvelope($staged);

        if (! $staged->successful()) {
            throw new RuntimeException(trim($staged->stderr) ?: 'Remote secret file staging failed.');
        }

        if (! array_key_exists('path', $data) || ! is_string($data['path']) || trim($data['path']) === '') {
            throw new RuntimeException('Remote secret file staging did not return a path.');
        }

        $path = $data['path'];

        try {
            return $callback($path);
        } finally {
            $this->localExecutor->runInternal(
                node: $node,
                commandName: 'internal:secret-file',
                arguments: ['remove'],
                transportOptions: [
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'secret-file-remove',
                    ],
                    'input' => json_encode([
                        'path' => $path,
                    ], JSON_THROW_ON_ERROR),
                    'strict' => false,
                    'throw' => false,
                ],
            );
        }
    }
}
