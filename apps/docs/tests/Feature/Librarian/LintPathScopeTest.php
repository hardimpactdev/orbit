<?php

declare(strict_types=1);

use App\Librarian\OrbitCommandDocs;
use App\Librarian\Rules\CommandDirectoryStructureRule;
use HardImpact\Librarian\Docs\DocsConfig;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->fixtureRoot = sys_get_temp_dir().'/orbit-lint-scope-'.bin2hex(random_bytes(6));

    mkdir("{$this->fixtureRoot}/content", 0777, true);

    config()->set('librarian.path', "{$this->fixtureRoot}/content");
    config()->set('librarian.rules', [
        CommandDirectoryStructureRule::class,
    ]);

    app()->forgetInstance(DocsConfig::class);
});

afterEach(function (): void {
    deleteLintScopeFixture($this->fixtureRoot);
});

it('reports app rule findings under a content-named docs root through --path=domains', function (): void {
    writeLintScopeFamily($this->fixtureRoot, withTechnicalContract: false);

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'domains',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['result'])->toBe('failed')
        ->and(findingsForLintScopeRule($payload, 'command_docs.command_directory_structure'))->not->toBeEmpty();
});

it('keeps findings outside the scoped path excluded', function (): void {
    writeLintScopeFamily($this->fixtureRoot, withTechnicalContract: false);

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'testing',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(findingsForLintScopeRule($payload, 'command_docs.command_directory_structure'))->toBeEmpty();
});

it('reports relative finding paths in the canonical docs namespace regardless of the docs directory name', function (): void {
    writeLintScopeFamily($this->fixtureRoot, withTechnicalContract: true);

    $docs = app(OrbitCommandDocs::class);

    expect($docs->relativePath("{$this->fixtureRoot}/content/domains/1_node/node.md"))
        ->toBe('docs/domains/1_node/node.md')
        ->and($docs->relativePath("{$this->fixtureRoot}/content"))->toBe('docs');
});

function writeLintScopeFamily(string $root, bool $withTechnicalContract): void
{
    writeLintScopeFile($root, 'content/domains/1_node/README.md', "# Node Commands\n");
    writeLintScopeFile($root, 'content/domains/1_node/node.md', "# Node\n\n## Purpose\n\nNode command contracts describe node behavior.\n");
    writeLintScopeFile($root, 'content/domains/1_node/1_node-new/node-new.md', "# `orbit node:new`\n\n[Technical](technical/1_node-new.md)\n");

    if (! $withTechnicalContract) {
        return;
    }

    writeLintScopeFile($root, 'content/domains/1_node/1_node-new/technical/1_node-new.md', "# Technical Contract: `orbit node:new`\n");
}

function writeLintScopeFile(string $root, string $path, string $contents): void
{
    $fullPath = "{$root}/{$path}";

    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0777, true);
    }

    file_put_contents($fullPath, $contents);
}

function deleteLintScopeFixture(string $path): void
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
function findingsForLintScopeRule(array $payload, string $rule): array
{
    return array_values(array_filter(
        $payload['findings'] ?? [],
        fn (array $finding): bool => $finding['rule'] === $rule,
    ));
}
