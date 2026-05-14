<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\CommandPageStructureRule;

/**
 * @param  array<string, string>  $files
 */
function commandPageStructureRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-command-page-structure-rule-'.bin2hex(random_bytes(6));

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

it('flags a public command page with no H2 sections', function (): void {
    $context = commandPageStructureRuleContext([
        'docs/commands/6_workspace/1_workspace-new/workspace-new.md' => <<<'MARKDOWN'
# `orbit workspace:new`

**Purpose:** Create a workspace.

**Behavior:**
- Does things.

**Inputs:**
- Stuff.
MARKDOWN,
    ]);

    expect((new CommandPageStructureRule)->check($context))->toHaveCount(1);
});

it('accepts a public command page with multiple H2 sections', function (): void {
    $context = commandPageStructureRuleContext([
        'docs/commands/6_workspace/1_workspace-new/workspace-new.md' => <<<'MARKDOWN'
# `orbit workspace:new`

Intro.

## Usage

Use it.

## Arguments and options

- thing
MARKDOWN,
    ]);

    expect((new CommandPageStructureRule)->check($context))->toBe([]);
});

it('does not lint technical contracts', function (): void {
    $context = commandPageStructureRuleContext([
        'docs/commands/6_workspace/1_workspace-new/technical/1_workspace-new.md' => <<<'MARKDOWN'
# Technical contract

Only one section.
MARKDOWN,
    ]);

    expect((new CommandPageStructureRule)->check($context))->toBe([]);
});

it('flags a page that uses the legacy **Purpose:**/**Description:** lead-in even when H2 sections exist', function (): void {
    $context = commandPageStructureRuleContext([
        'docs/commands/7_process/1_process-add/process-add.md' => <<<'MARKDOWN'
# `orbit process:add`

[Back.](../README.md)

**Purpose:** Add a process.

**Description:** Defines a managed process.

## Usage

```bash
orbit process:add foo
```

## Behavior

Runs the thing.

## Related

- [`process:edit`](../2_process-edit/process-edit.md)
MARKDOWN,
    ]);

    $findings = (new CommandPageStructureRule)->check($context);
    expect($findings)->toHaveCount(1);
    expect($findings[0]->message)->toContain('legacy');
});

it('does not lint family READMEs or concept pages', function (): void {
    $context = commandPageStructureRuleContext([
        'docs/commands/6_workspace/README.md' => "# Workspaces\n\nFamily intro.\n",
        'docs/commands/6_workspace/workspace-concepts.md' => "# Workspace concepts\n\nConcept page.\n",
    ]);

    expect((new CommandPageStructureRule)->check($context))->toBe([]);
});
