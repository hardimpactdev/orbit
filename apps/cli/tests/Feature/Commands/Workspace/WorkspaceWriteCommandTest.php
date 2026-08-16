<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace write commands', function (): void {
    it('posts workspace:new payloads to the gateway workspaces endpoint', function (): void {
        fakeGatewayProgressStream(
            "event: complete\n"
            .'data: {"exit_code":0,"data":{"success":{"data":{"result":{"action":"created"},"workspace":{"name":"feature-docs","app":"docs","instance":"development"}},"meta":{"node":"app-1","base":"main"}}}}'
            ."\n\n",
        );

        [$exitCode, $output] = runCommand($this, 'workspace:new', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--base' => 'main',
            '--php-version' => '8.5',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/workspaces'
                && $request->data() === [
                    'name' => 'feature-docs',
                    'instance' => 'docs',
                    'base' => 'main',
                    'php_version' => '8.5',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe([
                'success' => [
                    'data' => [
                        'result' => ['action' => 'created'],
                        'workspace' => [
                            'name' => 'feature-docs',
                            'app' => 'docs',
                            'instance' => 'development',
                        ],
                    ],
                    'meta' => [
                        'node' => 'app-1',
                        'base' => 'main',
                    ],
                ],
            ]);
    });

    it('validates workspace:new names before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace:new', [
            'name' => 'main',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('name');
    });

    it('posts workspace:setup payloads with caller cwd context', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/Users/nckrtl/Sites/docs/.worktrees/feature-docs');

        try {
            fakeGatewayProgressStream(
                "event: complete\n"
                .'data: {"exit_code":0,"data":{"result":{"workspace":"feature-docs","app":"docs","instance":"development","action":"set_up"}}}'
                ."\n\n",
            );

            [$exitCode] = runCommand($this, 'workspace:setup', [
                'name' => 'feature-docs',
                '--instance' => 'docs',
                '--path' => '/Users/nckrtl/Sites/docs/.worktrees/feature-docs',
                '--json' => true,
            ]);
        } finally {
            restoreHostCwd($previousHostCwd);
        }

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/workspaces/setup'
                && ! $request->hasHeader('X-Orbit-Node-Transport-Preference')
                && $request->data() === [
                    'name' => 'feature-docs',
                    'instance' => 'docs',
                    'path' => '/Users/nckrtl/Sites/docs/.worktrees/feature-docs',
                    'caller_cwd' => '/Users/nckrtl/Sites/docs/.worktrees/feature-docs',
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('derives the workspace name from synced Codex worktree branch metadata', function (): void {
        $path = createCodexWorktreeMetadata(
            key: '194238',
            repository: 'happie',
            metadata: [
                'codex-synced-branch.json' => [
                    'branch' => 'refs/heads/codex/auto-env-happie-194238',
                ],
            ],
        );

        try {
            fakeGatewayProgressStream("event: complete\ndata: {\"exit_code\":0}\n\n");

            [$exitCode] = runCommand($this, 'workspace:setup', [
                '--instance' => 'happie.nmbp',
                '--path' => $path,
                '--json' => true,
            ]);
        } finally {
            removeCodexWorktreeMetadata($path);
        }

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->data() === [
                'name' => 'codex-auto-env-happie-194238',
                'instance' => 'happie.nmbp',
                'path' => $path,
                'caller_cwd' => getcwd(),
            ],
        );

        expect($exitCode)->toBe(0);
    });

    it('derives the workspace name from the Codex worktree key when only thread metadata exists', function (): void {
        $path = createCodexWorktreeMetadata(
            key: '09dd',
            repository: 'happie',
            metadata: [
                'codex-thread.json' => [
                    'version' => 1,
                    'ownerThreadId' => '019f4821-eced-7562-ac39-8315438ab0ee',
                ],
            ],
        );

        try {
            fakeGatewayProgressStream("event: complete\ndata: {\"exit_code\":0}\n\n");

            [$exitCode] = runCommand($this, 'workspace:setup', [
                '--instance' => 'happie.nmbp',
                '--path' => $path,
                '--json' => true,
            ]);
        } finally {
            removeCodexWorktreeMetadata($path);
        }

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->data() === [
                'name' => 'codex-09dd',
                'instance' => 'happie.nmbp',
                'path' => $path,
                'caller_cwd' => getcwd(),
            ],
        );

        expect($exitCode)->toBe(0);
    });

    it('limits thread-derived Codex workspace names to a deterministic slug', function (): void {
        $key = str_repeat('a', 80);
        $path = createCodexWorktreeMetadata(
            key: $key,
            repository: 'happie',
            metadata: [
                'codex-thread.json' => [
                    'version' => 1,
                    'ownerThreadId' => '019f4821-eced-7562-ac39-8315438ab0ee',
                ],
            ],
        );

        try {
            fakeGatewayProgressStream("event: complete\ndata: {\"exit_code\":0}\n\n");

            [$exitCode] = runCommand($this, 'workspace:setup', [
                '--instance' => 'happie.nmbp',
                '--path' => $path,
                '--json' => true,
            ]);
        } finally {
            removeCodexWorktreeMetadata($path);
        }

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->data() === [
                'name' => 'codex-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-0738f769',
                'instance' => 'happie.nmbp',
                'path' => $path,
                'caller_cwd' => getcwd(),
            ],
        );

        expect($exitCode)->toBe(0);
    });

    it('requires force before removing a workspace non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace:remove', [
            'name' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('force');
    });

    it('deletes workspace:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'feature-docs',
            'app' => 'docs',
            'instance' => 'development',
            'action' => 'removed',
        ], [
            'kept_files' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:remove', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--keep-files' => true,
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/feature-docs?instance=docs'
                && ! $request->hasHeader('X-Orbit-Node-Transport-Preference')
                && $request->data() === [
                    'keep_files' => true,
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            );
        });

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['action'])->toBe('removed');
    });

    it('prompts for workspace:remove name and confirmation in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'feature-docs',
            'app' => 'docs',
            'instance' => 'development',
            'action' => 'removed',
        ]));

        $this
            ->artisan('workspace:remove', ['--instance' => 'docs'])
            ->expectsQuestion('Workspace name', 'feature-docs')
            ->expectsConfirmation("Remove workspace 'feature-docs'?", 'yes')
            ->expectsOutputToContain('removed')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'DELETE'
                && $url === 'https://gateway.test/api/workspaces/feature-docs?instance=docs'
                && $request->data() === [
                    'keep_files' => false,
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            );
        });
    });

    it('renders workspace:remove human output as a progress tree with a success footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'feature-api',
            'app' => 'my-app',
            'instance' => 'development',
            'action' => 'removed',
            'worktree_removed' => true,
        ], [
            'kept_files' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:remove', [
            'name' => 'feature-api',
            '--instance' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Removing Workspace')
            ->and($output)
            ->toContain('Apply and verify workspace removal')
            ->and($output)
            ->toContain('Removing worktree')
            ->and($output)
            ->toContain("Workspace 'feature-api' removed")
            ->and($output)
            ->not->toContain('action:')->and($output)
            ->not->toContain('{');
    });

    it('renders workspace:remove drift warnings after the tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'name' => 'feature-api',
            'app' => 'my-app',
            'instance' => 'development',
            'action' => 'removed',
            'worktree_removed' => false,
        ], [
            'kept_files' => false,
            'warnings' => [[
                'code' => 'workspace.artifact_extra',
                'family' => 'workspace',
                'message' => 'Workspace worktree could not be removed during cleanup.',
                'next_command' => 'doctor --family=workspace --restore',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:remove', [
            'name' => 'feature-api',
            '--instance' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Workspace 'feature-api' removed with drift")
            ->and($output)
            ->toContain('Drift detected:')
            ->and($output)
            ->toContain(
                'workspace: Workspace worktree could not be removed during cleanup. (run `doctor --family=workspace --restore`)',
            )
            ->and($output)
            ->not->toContain('{');
    });

    it('renders workspace:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('workspace.not_found', "Workspace 'feature-api' not found in registry."), 404);

        [$exitCode, $output] = runCommand($this, 'workspace:remove', [
            'name' => 'feature-api',
            '--instance' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain("Workspace 'feature-api' not found")
            ->and($output)
            ->not->toContain('"error"');
    });

    it('validates workspace:setup paths before opening a gateway stream', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace:setup', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--path' => 'relative/path',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('path');
    });
});

/**
 * @param  array<string, array<string, int|string>>  $metadata
 */
function createCodexWorktreeMetadata(string $key, string $repository, array $metadata): string
{
    $path =
        sys_get_temp_dir()
        ."/orbit-cli-codex-worktrees-{$key}-"
        .bin2hex(random_bytes(8))
        ."/.codex/worktrees/{$key}/{$repository}";
    $gitDirectory = dirname($path).'/git';

    mkdir($path, 0755, recursive: true);
    mkdir($gitDirectory, 0755, recursive: true);
    file_put_contents("{$path}/.git", "gitdir: {$gitDirectory}\n");

    foreach ($metadata as $filename => $contents) {
        file_put_contents(
            "{$gitDirectory}/{$filename}",
            json_encode($contents, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );
    }

    return $path;
}

function removeCodexWorktreeMetadata(string $path): void
{
    new \Illuminate\Filesystem\Filesystem()->deleteDirectory(dirname(dirname(dirname(dirname($path)))));
}
