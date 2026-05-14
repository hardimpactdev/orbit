<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\LongSectionStructureRule;

/**
 * @param  array<string, string>  $files
 */
function longSectionStructureRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-long-section-structure-rule-'.bin2hex(random_bytes(6));

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

it('flags a long section with no subheadings, lists, tables, code, or discourse markers', function (): void {
    $context = longSectionStructureRuleContext([
        'docs/intro.md' => <<<'MARKDOWN'
# Intro

## Wall of prose

Paragraph one is a simple declarative claim about the system.

Paragraph two states another fact about how the system behaves.

Paragraph three keeps describing more behavior in flat prose.

Paragraph four continues without any structural break.

Paragraph five closes the section with another normative sentence.
MARKDOWN,
    ]);

    $findings = (new LongSectionStructureRule)->check($context);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->message)->toContain('Wall of prose');
});

it('does not flag a section that contains a list', function (): void {
    $context = longSectionStructureRuleContext([
        'docs/intro.md' => <<<'MARKDOWN'
# Intro

## With a list

Paragraph one.

Paragraph two.

Paragraph three.

Paragraph four.

- item one
- item two
MARKDOWN,
    ]);

    expect((new LongSectionStructureRule)->check($context))->toBe([]);
});

it('does not flag a section that uses discourse markers', function (): void {
    $context = longSectionStructureRuleContext([
        'docs/intro.md' => <<<'MARKDOWN'
# Intro

## With markers

Paragraph one introduces the topic.

If the gateway is configured, the command runs successfully.

Paragraph three is another claim.

However, if the gateway is missing the command exits early.

Paragraph five concludes the explanation.
MARKDOWN,
    ]);

    expect((new LongSectionStructureRule)->check($context))->toBe([]);
});

it('exempts technical contract files', function (): void {
    $context = longSectionStructureRuleContext([
        'docs/commands/1_node/1_node-new/technical/1_node-new.md' => <<<'MARKDOWN'
# Node new technical contract

## Wall of prose

Paragraph one is normative.

Paragraph two states the contract.

Paragraph three keeps stating the contract.

Paragraph four also.

Paragraph five too.
MARKDOWN,
    ]);

    expect((new LongSectionStructureRule)->check($context))->toBe([]);
});
