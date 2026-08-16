<?php

declare(strict_types=1);

use App\Services\GatewayStreamClient;
use GuzzleHttp\Psr7\FnStream;
use Orbit\Core\Progress\ProgressEventType;
use Psr\Http\Message\StreamInterface;

/**
 * @param  array<string, mixed>  $data
 */
function sseFrame(string $event, array $data): string
{
    return "event: {$event}\n".'data: '.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n";
}

/**
 * @param  list<string>  $chunks
 */
function chunkedSseStream(array $chunks, ?callable $onRead = null): StreamInterface
{
    $index = 0;
    $position = 0;

    return new FnStream([
        '__toString' => function () use (&$chunks, &$index): string {
            return implode('', array_slice($chunks, $index));
        },
        'close' => fn (): null => null,
        'detach' => fn (): null => null,
        'getSize' => fn (): null => null,
        'tell' => fn (): int => $position,
        'eof' => fn (): bool => $index >= count($chunks),
        'isSeekable' => fn (): bool => false,
        'rewind' => function (): void {
            throw new RuntimeException('Cannot rewind test stream.');
        },
        'seek' => function (): void {
            throw new RuntimeException('Cannot seek test stream.');
        },
        'isWritable' => fn (): bool => false,
        'write' => function (): void {
            throw new RuntimeException('Cannot write test stream.');
        },
        'isReadable' => fn (): bool => true,
        'read' => function (int $length) use (&$chunks, &$index, &$position, $onRead): string {
            if ($index >= count($chunks)) {
                return '';
            }

            $chunk = $chunks[$index];
            $index++;
            $position += strlen($chunk);

            if ($onRead !== null) {
                $onRead($index, $chunk);
            }

            return $chunk;
        },
        'getContents' => function () use (&$chunks, &$index, &$position, $onRead): string {
            $contents = '';

            while ($index < count($chunks)) {
                $chunk = $chunks[$index];
                $index++;
                $position += strlen($chunk);
                $contents .= $chunk;

                if ($onRead !== null) {
                    $onRead($index, $chunk);
                }
            }

            return $contents;
        },
        'getMetadata' => fn (?string $key = null): mixed => $key === null ? [] : null,
    ]);
}

function fakeWorkspaceStreamGateway(string|StreamInterface $body, int $status = 200): void
{
    fakeGatewayProgressStream($body, $status);
}

describe('WorkspaceStream commands', function (): void {
    it('emits the same canonical workspace:new success data and meta in literal json and stream-json output', function (): void {
        $success = [
            'data' => [
                'result' => ['action' => 'created'],
                'workspace' => [
                    'name' => 'feature-docs',
                    'app' => 'docs',
                    'instance' => 'development',
                ],
            ],
            'meta' => ['node' => 'app-1', 'base' => 'main'],
        ];
        $complete = [
            'exit_code' => 0,
            'data' => [
                'footer' => "Workspace 'feature-docs' created",
                'success' => $success,
            ],
        ];

        fakeWorkspaceStreamGateway(
            sseFrame('tree', ['title' => 'Creating Workspace'])
                .sseFrame('step', ['key' => 'source', 'status' => 'running', 'message' => 'Provisioning worktree'])
                .sseFrame('complete', $complete),
        );

        [$jsonExitCode, $jsonOutput] = runCommand($this, 'workspace:new', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--base' => 'main',
            '--php-version' => '8.5',
            '--json' => true,
        ]);

        fakeWorkspaceStreamGateway(sseFrame('complete', $complete));

        [$streamExitCode, $streamOutput] = runCommand($this, 'workspace:new', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--base' => 'main',
            '--php-version' => '8.5',
            '--stream-json' => true,
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/workspaces'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'name' => 'feature-docs',
                    'instance' => 'docs',
                    'base' => 'main',
                    'php_version' => '8.5',
                ]
            ),
        );

        expect($jsonExitCode)
            ->toBe(0)
            ->and(json_decode($jsonOutput, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['success' => $success])
            ->and($streamExitCode)
            ->toBe(0)
            ->and(json_decode($streamOutput, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                'event' => 'complete',
                'success' => $success,
            ])
            ->and(count(array_filter(explode("\n", $jsonOutput))))
            ->toBe(1)
            ->and(count(array_filter(explode("\n", $streamOutput))))
            ->toBe(1)
            ->and($jsonOutput)
            ->not->toContain('Provisioning worktree')->and($streamOutput)
            ->not->toContain('Provisioning worktree');
    });

    it('renders gateway-authored workspace setup progress in human mode', function (): void {
        fakeWorkspaceStreamGateway(
            sseFrame('tree', [
                'title' => 'Setting Up Workspace',
                'steps' => [
                    ['key' => 'registry', 'label' => 'Apply workspace registry'],
                ],
            ])
                .sseFrame('step', [
                    'key' => 'registry',
                    'status' => 'running',
                    'message' => 'Applying workspace registry',
                ])
                .sseFrame('complete', [
                    'exit_code' => 0,
                    'data' => [
                        'footer' => 'Workspace ready and available at: https://feature-docs.docs.test',
                    ],
                ]),
        );

        [$exitCode, $output] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--path' => '/Users/nckrtl/Sites/docs/.worktrees/feature-docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Setting Up Workspace')
            ->and($output)
            ->toContain('Apply workspace registry')
            ->and($output)
            ->toContain('Applying workspace registry')
            ->and($output)
            ->toContain('Workspace ready and available at: https://feature-docs.docs.test');
    });

    it('emits the same canonical setup success data and meta in json and terminal stream-json output', function (): void {
        $success = [
            'data' => [
                'result' => ['action' => 'adopted'],
                'workspace' => [
                    'name' => 'feature-docs',
                    'app' => 'docs',
                    'instance' => 'development',
                    'node' => 'app-1',
                    'path' => '/srv/docs/.worktrees/feature-docs',
                    'url' => 'https://feature-docs.docs.test',
                    'php_version' => '8.5',
                    'php_inherited' => false,
                    'adopted' => true,
                    'lifecycle_status' => 'expected',
                ],
            ],
            'meta' => [
                'node' => 'app-1',
                'http_probe' => [
                    'url' => 'https://feature-docs.docs.test',
                    'result' => 'healthy',
                    'status_code' => 200,
                    'duration_ms' => 12,
                ],
                'warnings' => [],
            ],
        ];
        $complete = [
            'exit_code' => 0,
            'data' => [
                'footer' => 'Workspace ready and available at: https://feature-docs.docs.test',
                'success' => $success,
            ],
        ];

        fakeWorkspaceStreamGateway(sseFrame('complete', $complete));

        [$jsonExitCode, $jsonOutput] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--instance' => 'docs.development',
            '--json' => true,
        ]);

        fakeWorkspaceStreamGateway(sseFrame('complete', $complete));

        [$streamExitCode, $streamOutput] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--instance' => 'docs.development',
            '--stream-json' => true,
        ]);

        expect($jsonExitCode)
            ->toBe(0)
            ->and(json_decode($jsonOutput, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['success' => $success])
            ->and($streamExitCode)
            ->toBe(0)
            ->and(json_decode($streamOutput, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                'event' => 'complete',
                'success' => $success,
            ]);
    });

    it('emits only the final workspace setup error frame in json mode', function (): void {
        $error = [
            'exit_code' => 1,
            'message' => 'Setup step failed.',
            'data' => [
                'code' => 'workspace.setup_step_failed',
                'message' => 'Setup step failed.',
                'meta' => ['step' => 'composer install'],
            ],
        ];

        fakeWorkspaceStreamGateway(
            sseFrame('tree', ['title' => 'Setting Up Workspace'])
                .sseFrame('step', ['key' => 'setup', 'status' => 'running', 'message' => 'Running composer install'])
                .sseFrame('error', $error),
        );

        [$exitCode, $output] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe([
                'error' => $error['data'],
            ])
            ->and(count(array_filter(explode("\n", $output))))
            ->toBe(1)
            ->and($output)
            ->not->toContain('Running composer install');
    });

    it('ignores SSE keepalive comments while waiting for the terminal frame', function (): void {
        fakeWorkspaceStreamGateway(
            ": heartbeat\n\n".sseFrame('complete', ['exit_code' => 0, 'data' => ['footer' => 'Workspace ready']]),
        );

        [$exitCode, $output] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe([
                'success' => [
                    'data' => ['footer' => 'Workspace ready'],
                    'meta' => [],
                ],
            ])
            ->and($output)
            ->not->toContain('heartbeat');
    });

    it('fails when the workspace stream contains a malformed frame', function (): void {
        fakeWorkspaceStreamGateway("event: step\ndata: not-json\n\n");

        [$exitCode, $output] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('gateway_unavailable')
            ->and($decoded['error']['message'])
            ->toContain('malformed');
    });

    it('fails when the workspace stream closes without a terminal frame', function (): void {
        fakeWorkspaceStreamGateway(sseFrame('step', ['key' => 'registry', 'status' => 'running']));

        [$exitCode, $output] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('dispatches decoded frames before the full response body is read', function (): void {
        $readCount = 0;
        $eventReadCounts = [];

        $httpClient = fakeGatewayProgressStreamClient(chunkedSseStream([
            sseFrame('step', ['key' => 'source', 'status' => 'running']),
            sseFrame('complete', ['exit_code' => 0, 'data' => []]),
        ], function (int $count) use (&$readCount): void {
            $readCount = $count;
        }));

        $exitCode = app(GatewayStreamClient::class)
            ->streamEvents('/api/workspaces', [], function (ProgressEventType $type) use (
                &$readCount,
                &$eventReadCounts,
            ): void {
                $eventReadCounts[$type->value] = $readCount;
            });

        expect($exitCode)
            ->toBe(0)
            ->and($eventReadCounts)
            ->toBe([
                'step' => 1,
                'complete' => 2,
            ]);
    });
});
