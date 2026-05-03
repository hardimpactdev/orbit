<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\ConceptIndexRule;

/**
 * @param  array<string, string>  $files
 */
function conceptIndexRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-concept-index-rule-'.bin2hex(random_bytes(6));

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
        scanRoot: "{$root}/docs/CONCEPTS.md",
    );
}

it('allows product acronym headings for hyphenated concept families', function (): void {
    $rule = new ConceptIndexRule;
    $context = conceptIndexRuleContext([
        'docs/CONCEPTS.md' => <<<'MARKDOWN'
# Concepts

## Agent IDE Concepts

Source: [Agent IDE Concepts](commands/15_agent-ide/agent-ide-concepts.md).

<!-- concept-index:commands/15_agent-ide/agent-ide-concepts.md -->
- **Agent IDE adapter**
<!-- /concept-index -->
MARKDOWN,
        'docs/commands/15_agent-ide/agent-ide-concepts.md' => <<<'MARKDOWN'
# Agent IDE Concepts

- **Agent IDE adapter:** Gateway-registered adapter implementation.
MARKDOWN,
    ]);

    expect($rule->check($context))->toBe([]);
});
