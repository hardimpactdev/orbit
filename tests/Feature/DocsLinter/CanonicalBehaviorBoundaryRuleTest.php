<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\CanonicalBehaviorBoundaryRule;

function canonicalBehaviorBoundaryContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-docs-linter-'.bin2hex(random_bytes(6));
    $directory = "{$root}/docs/commands/1_node/1_node-sample/technical";

    mkdir($directory, recursive: true);

    foreach ($files as $name => $contents) {
        file_put_contents("{$directory}/{$name}", $contents);
    }

    return new CommandDocsLintContext(
        repositoryRoot: $root,
        scanRoot: "{$root}/docs/commands",
    );
}

it('allows renderer selection in the input contract and generic renderer references in behavior', function (): void {
    $rule = new CanonicalBehaviorBoundaryRule;
    $context = canonicalBehaviorBoundaryContext([
        '1_node-sample.md' => <<<'MARKDOWN'
# Technical Contract: `orbit node:sample`

## Input Contract

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Select the output renderer using the shared invocation model.
2. Resolve the target.

## Behavior Contract

### Target Rules

- Return the selected node through the selected output renderer.
- Preserve one ordered result list for every renderer.
MARKDOWN,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('flags renderer-specific branches in canonical input resolution and behavior sections', function (): void {
    $rule = new CanonicalBehaviorBoundaryRule;
    $context = canonicalBehaviorBoundaryContext([
        '1_node-sample.md' => <<<'MARKDOWN'
# Technical Contract: `orbit node:sample`

## Input Resolution

1. In the JSON renderer, resolve the local target before the gateway request.

## Behavior Contract

### Target Rules

- The human renderer displays grouped rows.
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('command_docs.canonical_behavior_boundaries')
        ->and($findings[0]->message)->toContain('Renderer-specific')
        ->and($findings[1]->message)->toContain('Renderer-specific');
});

it('flags numeric exit-status policy in canonical behavior sections', function (): void {
    $rule = new CanonicalBehaviorBoundaryRule;
    $context = canonicalBehaviorBoundaryContext([
        '1_node-sample.md' => <<<'MARKDOWN'
# Technical Contract: `orbit node:sample`

## Behavior Contract

### Failure Rules

- Success exits `0`; handled failures exit `1`.
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('Exit-status');
});
