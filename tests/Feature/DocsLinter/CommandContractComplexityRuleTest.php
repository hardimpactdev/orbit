<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\CommandContractComplexityRule;

function commandContractComplexityContext(string $contents): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-docs-linter-'.bin2hex(random_bytes(6));
    $directory = "{$root}/docs/commands/1_node/1_node-sample/technical";

    mkdir($directory, recursive: true);
    file_put_contents("{$directory}/1_node-sample.md", $contents);

    return new CommandDocsLintContext(
        repositoryRoot: $root,
        scanRoot: "{$root}/docs/commands",
    );
}

function commandContractComplexityFixture(int $behaviorItems): string
{
    $inputRows = [];

    foreach (range(1, 8) as $index) {
        $required = $index === 8 ? 'Optional.' : 'Required when sample path applies.';
        $inputRows[] = "| `field_{$index}` | option | {$required} | Never. | none | Sample field. |";
    }

    $behaviorRows = [];

    foreach (range(1, $behaviorItems) as $index) {
        $boundary = $index <= 8
            ? 'must not cross the documented sample boundary'
            : 'stays inside the documented sample path';

        $behaviorRows[] = "- Behavior item {$index} {$boundary}.";
    }

    return <<<'MARKDOWN'
---
Owner: docs
Effects: read
---

# Sample Command

## Signature

`orbit node:sample`

## Input Contract

| Field | Source | Required | Prompted | Default | Validation |
| --- | --- | --- | --- | --- | --- |
MARKDOWN
        ."\n"
        .implode("\n", $inputRows)
        .<<<'MARKDOWN'


## Caller Role Behavior

| Caller role | Behavior |
| --- | --- |
| control | Allowed. |
| gateway | Allowed. |
| app | Denied. |
| unknown | Denied. |

## Behavior Contract

MARKDOWN
        ."\n"
        .implode("\n", $behaviorRows)
        .<<<'MARKDOWN'


## Failure Semantics

| Failure | Result |
| --- | --- |
| One | Failure. |
| Two | Failure. |
| Three | Failure. |
| Four | Failure. |
| Five | Failure. |
| Six | Failure. |
| Seven | Failure. |
| Eight | Failure. |
| Nine | Failure. |

## Doctor Relationship

- `doctor --family=node` handles one.
- `doctor --family=node` handles two.
- `doctor --family=node` handles three.
MARKDOWN;
}

it('allows complete converted contracts near the calibrated ceiling', function (): void {
    $rule = new CommandContractComplexityRule;
    $context = commandContractComplexityContext(commandContractComplexityFixture(24));

    expect($rule->check($context))->toBe([]);
});

it('still warns when a contract grows past the calibrated ceiling', function (): void {
    $rule = new CommandContractComplexityRule;
    $context = commandContractComplexityContext(commandContractComplexityFixture(25));

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.command_contract_complexity');
});
