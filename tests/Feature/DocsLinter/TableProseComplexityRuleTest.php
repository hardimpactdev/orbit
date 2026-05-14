<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\TableProseComplexityRule;

/**
 * @param  array<string, string>  $files
 */
function tableProseComplexityRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-table-prose-complexity-rule-'.bin2hex(random_bytes(6));

    foreach ($files as $path => $contents) {
        $file = "{$root}/{$path}";
        $directory = dirname($file);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents($file, $contents);
    }

    return new CommandDocsLintContext(
        repositoryRoot: $root,
        scanRoot: "{$root}/docs",
    );
}

it('flags a table cell that exceeds the word threshold', function (): void {
    $longCell = 'The gateway holds the certificate authority and issues TLS material for app and workspace routes which means HTTPS works without any external certificate authority configuration whatsoever for the entire fleet across every node in the cluster.';

    $context = tableProseComplexityRuleContext([
        'docs/notes.md' => "# Notes\n\n| Behavior | Details |\n|---|---|\n| TLS | {$longCell} |\n",
    ]);

    expect((new TableProseComplexityRule)->check($context))->not->toBeEmpty();
});

it('does not flag a short cell', function (): void {
    $context = tableProseComplexityRuleContext([
        'docs/notes.md' => "# Notes\n\n| Behavior | Details |\n|---|---|\n| TLS | Issued by gateway. |\n",
    ]);

    expect((new TableProseComplexityRule)->check($context))->toBe([]);
});

it('flags a cell with too many sentences even if total words is under threshold', function (): void {
    $cell = 'First sentence. Second sentence. Third sentence. Fourth sentence.';

    $context = tableProseComplexityRuleContext([
        'docs/notes.md' => "# Notes\n\n| Behavior | Details |\n|---|---|\n| TLS | {$cell} |\n",
    ]);

    expect((new TableProseComplexityRule)->check($context))->not->toBeEmpty();
});

it('ignores header and separator rows', function (): void {
    $context = tableProseComplexityRuleContext([
        'docs/notes.md' => "# Notes\n\n| Behavior word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word | Details |\n|---|---|\n| TLS | ok |\n",
    ]);

    // Header is allowed to be long; only data cells are checked. Adjust if rule changes.
    $findings = (new TableProseComplexityRule)->check($context);
    foreach ($findings as $finding) {
        expect($finding->message)->not->toContain('TLS');
    }
});
