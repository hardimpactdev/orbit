<?php

declare(strict_types=1);

use App\Librarian\CliCommand;
use App\Librarian\CliSurface;
use App\Librarian\Rules\BannedTermsRule;
use App\Librarian\Rules\CommandSurfaceCoverageRule;
use App\Librarian\Rules\PublicStreamJsonOptionContractRule;
use App\Librarian\Rules\SignatureLiveSurfaceRule;
use HardImpact\Librarian\Docs\DocsConfig;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->fixtureRoot = sys_get_temp_dir().'/orbit-live-surface-'.bin2hex(random_bytes(6));

    mkdir("{$this->fixtureRoot}/content", 0777, true);

    config()->set('librarian.path', "{$this->fixtureRoot}/content");
    config()->set('librarian.banned_terms', []);

    app()->forgetInstance(DocsConfig::class);
});

afterEach(function (): void {
    deleteLiveSurfaceFixture($this->fixtureRoot);
});

it('reports a public command without a command doc directory', function (): void {
    config()->set('librarian.rules', [CommandSurfaceCoverageRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'node:new', arguments: ['name'], options: ['json']),
        new CliCommand(name: 'gateway:status', options: ['json']),
    ]);
    writeLiveSurfaceFamily($this->fixtureRoot);

    $payload = runLiveSurfaceLint();

    $findings = liveSurfaceFindings($payload, 'command_docs.live_surface_coverage');

    expect($payload['result'])
        ->toBe('failed')
        ->and($findings)
        ->toHaveCount(1)
        ->and($findings[0]['message'])
        ->toContain('gateway:status')
        ->and($findings[0]['message'])
        ->toContain('gateway-status');
});

it('reports a command doc directory without a public command', function (): void {
    config()->set('librarian.rules', [CommandSurfaceCoverageRule::class]);
    bindLiveSurfaceFake([]);
    writeLiveSurfaceFamily($this->fixtureRoot);

    $payload = runLiveSurfaceLint();

    $findings = liveSurfaceFindings($payload, 'command_docs.live_surface_coverage');

    expect($payload['result'])
        ->toBe('failed')
        ->and($findings)
        ->toHaveCount(1)
        ->and($findings[0]['path'])
        ->toBe('docs/domains/1_node/1_node-new')
        ->and($findings[0]['message'])
        ->toContain('node-new');
});

it('allows an offline command doc directory marked removed or reserved', function (): void {
    config()->set('librarian.rules', [CommandSurfaceCoverageRule::class]);
    bindLiveSurfaceFake([]);
    writeLiveSurfaceFamily(
        $this->fixtureRoot,
        publicPage: "# `orbit node:new`\n\n<!-- command-status: reserved -->\n\n[Technical](technical/1_node-new.md)\n",
    );

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.live_surface_coverage'))->toBeEmpty();
});

it('passes surface coverage when every public command is documented', function (): void {
    config()->set('librarian.rules', [CommandSurfaceCoverageRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'node:new', arguments: ['name'], options: ['json']),
    ]);
    writeLiveSurfaceFamily($this->fixtureRoot);

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.live_surface_coverage'))->toBeEmpty();
});

it('maps space-namespaced command names onto command doc slugs', function (): void {
    config()->set('librarian.rules', [CommandSurfaceCoverageRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'node:new', arguments: ['name'], options: ['json']),
        new CliCommand(name: 'node role:add', arguments: ['node', 'role'], options: ['json', 'tld']),
    ]);
    writeLiveSurfaceFamily($this->fixtureRoot);
    writeLiveSurfaceCommandDirectory(
        $this->fixtureRoot,
        'domains/1_node/2_node-role-add',
        'node-role-add',
        'orbit node role:add <node> <role> [--tld=<tld>] [--json]',
    );

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.live_surface_coverage'))->toBeEmpty();
});

it('reports signature drift against the live command definition', function (): void {
    config()->set('librarian.rules', [SignatureLiveSurfaceRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'node:new', arguments: ['name'], options: ['json', 'template']),
    ]);
    writeLiveSurfaceFamily($this->fixtureRoot, signature: 'orbit node:new <name> [--json] [--legacy-flag]');

    $payload = runLiveSurfaceLint();

    $findings = liveSurfaceFindings($payload, 'command_docs.signature_live_surface');

    expect($payload['result'])
        ->toBe('failed')
        ->and($findings)
        ->toHaveCount(1)
        ->and($findings[0]['path'])
        ->toBe('docs/domains/1_node/1_node-new/technical/1_node-new.md')
        ->and($findings[0]['message'])
        ->toContain('--legacy-flag')
        ->and($findings[0]['message'])
        ->toContain('--template');
});

it('reports argument drift against the live command definition', function (): void {
    config()->set('librarian.rules', [SignatureLiveSurfaceRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'node:new', arguments: ['name', 'host'], options: ['json']),
    ]);
    writeLiveSurfaceFamily($this->fixtureRoot, signature: 'orbit node:new <host> <name> [--json]');

    $payload = runLiveSurfaceLint();

    $findings = liveSurfaceFindings($payload, 'command_docs.signature_live_surface');

    expect($payload['result'])
        ->toBe('failed')
        ->and($findings)
        ->toHaveCount(1)
        ->and($findings[0]['message'])
        ->toContain('name, host');
});

it('passes signatures that match the live command definition', function (): void {
    config()->set('librarian.rules', [SignatureLiveSurfaceRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'node:new', arguments: ['name'], options: ['json', 'with-process', 'no-process']),
    ]);
    writeLiveSurfaceFamily(
        $this->fixtureRoot,
        signature: 'orbit node:new <name> [--with-process|--no-process] [--json]',
    );

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.signature_live_surface'))->toBeEmpty();
});

it('reports a public command page that omits a live stream json option', function (): void {
    config()->set('librarian.rules', [PublicStreamJsonOptionContractRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'node:new', arguments: ['name'], options: ['json', 'stream-json']),
    ]);
    writeLiveSurfaceFamily(
        $this->fixtureRoot,
        signature: 'orbit node:new <name> [--json] [--stream-json]',
        publicPage: "# `orbit node:new`\n\nUse `--json` for automation.\n\n[Technical](technical/1_node-new.md)\n",
    );

    $payload = runLiveSurfaceLint();

    $findings = liveSurfaceFindings($payload, rule: 'command_docs.public_stream_json_option_contract');

    expect($payload['result'])
        ->toBe('failed')
        ->and($findings)
        ->toHaveCount(1)
        ->and($findings[0]['path'])
        ->toBe('docs/domains/1_node/1_node-new/node-new.md')
        ->and($findings[0]['message'])
        ->toContain('--stream-json');
});

it('passes public command pages that mention a live stream json option', function (): void {
    config()->set('librarian.rules', [PublicStreamJsonOptionContractRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'node:new', arguments: ['name'], options: ['json', 'stream-json']),
    ]);
    writeLiveSurfaceFamily(
        $this->fixtureRoot,
        signature: 'orbit node:new <name> [--json] [--stream-json]',
        publicPage: "# `orbit node:new`\n\nUse `--stream-json` for newline-delimited progress frames.\n\n[Technical](technical/1_node-new.md)\n",
    );

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, rule: 'command_docs.public_stream_json_option_contract'))->toBeEmpty();
});

it('maps analytics update internal version option onto the public signature', function (): void {
    config()->set('librarian.rules', [SignatureLiveSurfaceRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(name: 'analytics:update', options: ['requested-version', 'node', 'json']),
    ]);
    writeLiveSurfaceCommandDirectory(
        $this->fixtureRoot,
        'domains/21_analytics/1_analytics-update',
        'analytics-update',
        'orbit analytics:update [--node=<node>] [--version=<version>] [--json]',
    );

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.signature_live_surface'))->toBeEmpty();
});

it('maps process add internal service version option onto the public signature', function (): void {
    config()->set('librarian.rules', [SignatureLiveSurfaceRule::class]);
    bindLiveSurfaceFake([
        new CliCommand(
            name: 'process:add',
            arguments: ['name', 'process_command'],
            options: [
                'node',
                'app',
                'workspace',
                'tool',
                'service',
                'service-version',
                'image',
                'restart-policy',
                'crash-notification',
                'runtime',
                'start',
                'no-start',
                'json',
            ],
        ),
    ]);
    writeLiveSurfaceCommandDirectory(
        $this->fixtureRoot,
        'domains/7_process/1_process-add',
        'process-add',
        'orbit process:add [name] [process_command] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--tool=<tool>] [--service=<mysql|redis>] [--version=<version>] [--image=<image>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none|agent_ide>] [--runtime=<docker|docker-swarm|systemd>] [--start] [--no-start] [--json]',
    );

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.signature_live_surface'))->toBeEmpty();
});

it('skips signature checks for commands without a live counterpart', function (): void {
    config()->set('librarian.rules', [SignatureLiveSurfaceRule::class]);
    bindLiveSurfaceFake([]);
    writeLiveSurfaceFamily($this->fixtureRoot, signature: 'orbit node:new <name> [--json]');

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.signature_live_surface'))->toBeEmpty();
});

it('reports banned terms outside their allow paths', function (): void {
    config()->set('librarian.rules', [BannedTermsRule::class]);
    config()->set('librarian.banned_terms', [
        [
            'terms' => ['tool:start'],
            'decision' => '2026-06-06 tool lifecycle is process-owned (solo todo #703)',
            'replacement' => 'process:start',
            'allow_paths' => [],
        ],
    ]);
    bindLiveSurfaceFake([]);
    writeLiveSurfaceFile(
        $this->fixtureRoot,
        'content/domains/7_process/process-concepts.md',
        "# Process Concepts\n\nUse `tool:start` to start tools.\n",
    );

    $payload = runLiveSurfaceLint();

    $findings = liveSurfaceFindings($payload, 'command_docs.banned_terms');

    expect($payload['result'])
        ->toBe('failed')
        ->and($findings)
        ->toHaveCount(1)
        ->and($findings[0]['path'])
        ->toBe('docs/domains/7_process/process-concepts.md')
        ->and($findings[0]['line'])
        ->toBe(3)
        ->and($findings[0]['message'])
        ->toContain('tool:start')
        ->and($findings[0]['message'])
        ->toContain('process:start');
});

it('does not flag terms that only embed a banned term as a substring', function (): void {
    config()->set('librarian.rules', [BannedTermsRule::class]);
    config()->set('librarian.banned_terms', [
        [
            'terms' => ['tool:start'],
            'decision' => '2026-06-06 tool lifecycle is process-owned',
            'replacement' => 'process:start',
            'allow_paths' => [],
        ],
    ]);
    bindLiveSurfaceFake([]);
    writeLiveSurfaceFile(
        $this->fixtureRoot,
        'content/domains/3_tool/tool-concepts.md',
        "# Tool Concepts\n\nThe `tool:started-elsewhere` token is unrelated, as is `mytool:start`.\n",
    );

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.banned_terms'))->toBeEmpty();
});

it('allows banned terms under an allowed directory path', function (): void {
    config()->set('librarian.rules', [BannedTermsRule::class]);
    config()->set('librarian.banned_terms', [
        [
            'terms' => ['app:exec'],
            'decision' => '2026-06-03 Orbit has no command-exec surface',
            'replacement' => 'run artisan directly on the app node host toolchain',
            'allow_paths' => ['domains/5_app/README.md'],
        ],
    ]);
    bindLiveSurfaceFake([]);
    writeLiveSurfaceFile(
        $this->fixtureRoot,
        'content/domains/5_app/README.md',
        "# App Commands\n\n10. Reserved. `app:exec` was removed.\n",
    );

    $payload = runLiveSurfaceLint();

    expect(liveSurfaceFindings($payload, 'command_docs.banned_terms'))->toBeEmpty();
});

function bindLiveSurfaceFake(array $commands): void
{
    app()->instance(CliSurface::class, new readonly class($commands) implements CliSurface {
        public function __construct(
            private array $commands,
        ) {}

        public function publicCommands(): array
        {
            return $this->commands;
        }
    });
}

/**
 * @return array{result?: string, findings?: list<array{path: string, line?: int|null, severity: string, rule: string, message: string}>}
 */
function runLiveSurfaceLint(): array
{
    Artisan::call('librarian:lint', ['--format' => 'agent']);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

function writeLiveSurfaceFamily(
    string $root,
    string $signature = 'orbit node:new <name> [--json]',
    string $publicPage = "# `orbit node:new`\n\n[Technical](technical/1_node-new.md)\n",
): void {
    writeLiveSurfaceFile($root, 'content/domains/1_node/README.md', "# Node Commands\n");
    writeLiveSurfaceFile(
        $root,
        'content/domains/1_node/node.md',
        "# Node\n\n## Purpose\n\nNode command contracts describe node behavior.\n",
    );
    writeLiveSurfaceCommandDirectory($root, 'domains/1_node/1_node-new', 'node-new', $signature, $publicPage);
}

function writeLiveSurfaceCommandDirectory(
    string $root,
    string $directory,
    string $slug,
    string $signature,
    ?string $publicPage = null,
): void {
    $publicPage ??= "# `orbit {$slug}`\n\n[Technical](technical/1_{$slug}.md)\n";

    writeLiveSurfaceFile($root, "content/{$directory}/{$slug}.md", $publicPage);
    writeLiveSurfaceFile(
        $root,
        "content/{$directory}/technical/1_{$slug}.md",
        "# Technical Contract: `{$signature}`\n\n## Signature\n\n```bash\n{$signature}\n```\n",
    );
}

function writeLiveSurfaceFile(string $root, string $path, string $contents): void
{
    $fullPath = "{$root}/{$path}";

    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0777, true);
    }

    file_put_contents($fullPath, $contents);
}

function deleteLiveSurfaceFixture(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}

/**
 * @param  array{findings?: list<array{path: string, line?: int|null, severity: string, rule: string, message: string}>}  $payload
 * @return list<array{path: string, line?: int|null, severity: string, rule: string, message: string}>
 */
function liveSurfaceFindings(array $payload, string $rule): array
{
    return array_values(array_filter(
        $payload['findings'] ?? [],
        fn (array $finding): bool => $finding['rule'] === $rule,
    ));
}
