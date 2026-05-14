<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintSeverity;
use OrbitDocsLinter\Rules\SentenceCaseHeadingRule;

/**
 * @param  array<string, string>  $files
 */
function sentenceCaseHeadingRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-sentence-case-heading-rule-'.bin2hex(random_bytes(6));

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

it('flags title-case headings with capitalized function words', function (): void {
    $context = sentenceCaseHeadingRuleContext([
        'docs/ARCHITECTURE.md' => <<<'MARKDOWN'
# Architecture

## Hub And Spoke

Body.

## State Model

Body.
MARKDOWN,
    ]);

    $findings = (new SentenceCaseHeadingRule)->check($context);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->severity)->toBe(CommandDocsLintSeverity::Warning);
    expect($findings[0]->message)->toContain('Hub And Spoke');
});

it('accepts sentence-case headings, headings with acronyms, and code-spanned headings', function (): void {
    $context = sentenceCaseHeadingRuleContext([
        'docs/ARCHITECTURE.md' => <<<'MARKDOWN'
# Architecture

## Hub and spoke

Body.

## Command and API model

Body.

## `orbit node:new` reference

Body.

## PHP-FPM pool layout

Body.
MARKDOWN,
    ]);

    expect((new SentenceCaseHeadingRule)->check($context))->toBe([]);
});

it('does not lint headings inside fenced code blocks', function (): void {
    $context = sentenceCaseHeadingRuleContext([
        'docs/ARCHITECTURE.md' => <<<'MARKDOWN'
# Architecture

## Setup

```bash
# Run This Now
echo "ok"
```

Body.
MARKDOWN,
    ]);

    expect((new SentenceCaseHeadingRule)->check($context))->toBe([]);
});
