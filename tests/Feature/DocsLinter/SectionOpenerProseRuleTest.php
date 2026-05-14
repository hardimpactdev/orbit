<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\SectionOpenerProseRule;

/**
 * @param  array<string, string>  $files
 */
function sectionOpenerProseRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-section-opener-prose-rule-'.bin2hex(random_bytes(6));

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

it('flags a section that opens with a code block', function (): void {
    $context = sectionOpenerProseRuleContext([
        'docs/notes.md' => <<<'MARKDOWN'
# Notes

## Code-first section

```bash
echo "no prose"
```
MARKDOWN,
    ]);

    expect((new SectionOpenerProseRule)->check($context))->toHaveCount(1);
});

it('flags a section that opens with a table', function (): void {
    $context = sectionOpenerProseRuleContext([
        'docs/notes.md' => <<<'MARKDOWN'
# Notes

## Table-first section

| Column | Value |
|---|---|
| A | 1 |
MARKDOWN,
    ]);

    expect((new SectionOpenerProseRule)->check($context))->toHaveCount(1);
});

it('does not flag a section that opens with a prose sentence', function (): void {
    $context = sectionOpenerProseRuleContext([
        'docs/notes.md' => <<<'MARKDOWN'
# Notes

## Well-introduced section

This section describes what the thing is and when to use it.

```bash
echo "now the code"
```
MARKDOWN,
    ]);

    expect((new SectionOpenerProseRule)->check($context))->toBe([]);
});

it('exempts technical contract files', function (): void {
    $context = sectionOpenerProseRuleContext([
        'docs/commands/1_node/1_node-new/technical/1_node-new.md' => <<<'MARKDOWN'
# Technical

## Schema

| Field | Type |
|---|---|
| name | string |
MARKDOWN,
    ]);

    expect((new SectionOpenerProseRule)->check($context))->toBe([]);
});
