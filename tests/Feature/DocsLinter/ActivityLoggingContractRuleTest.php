<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\ActivityLoggingContractRule;

/**
 * @param  array<string, string>  $files
 */
function activityLoggingContractContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-activity-logging-contract-'.bin2hex(random_bytes(6));
    $commandsRoot = "{$root}/docs/commands";

    foreach ($files as $path => $contents) {
        $file = "{$commandsRoot}/{$path}";
        $directory = dirname($file);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents($file, $contents);
    }

    return new CommandDocsLintContext(
        repositoryRoot: $root,
        scanRoot: $commandsRoot,
    );
}

it('flags enforced commands missing the activity logging section', function (): void {
    $rule = new ActivityLoggingContractRule;
    $context = activityLoggingContractContext([
        '17_activity/1_activity-list/technical/1_activity-list.md' => <<<'MD'
# Technical Contract: `orbit activity:list`

**Owner:** `activity`.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.activity_logging_contract')
        ->and($findings[0]->message)->toContain('must include `## Activity Logging`');
});

it('flags enforced commands whose activity logging section is missing required fields', function (): void {
    $rule = new ActivityLoggingContractRule;
    $context = activityLoggingContractContext([
        '17_activity/1_activity-list/technical/1_activity-list.md' => <<<'MD'
# Technical Contract: `orbit activity:list`

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `activity.listed` |
| Effect | `read` |

## Doctor Relationship
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(2);

    $messages = array_map(fn ($finding) => $finding->message, $findings);

    expect($messages)->toContain('`## Activity Logging` must include a `Subject` row, or declare the command does not emit.')
        ->and($messages)->toContain('`## Activity Logging` must include a `Properties` row, or declare the command does not emit.');
});

it('accepts enforced commands with a complete activity logging table', function (): void {
    $rule = new ActivityLoggingContractRule;
    $context = activityLoggingContractContext([
        '17_activity/1_activity-list/technical/1_activity-list.md' => <<<'MD'
# Technical Contract: `orbit activity:list`

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `activity.listed` |
| Effect | `read` |
| Subject | `null`. |
| Properties | `filter_app`, `filter_node`. |

## Doctor Relationship
MD,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('accepts enforced commands that explicitly declare they do not emit', function (): void {
    $rule = new ActivityLoggingContractRule;
    $context = activityLoggingContractContext([
        '17_activity/1_activity-list/technical/1_activity-list.md' => <<<'MD'
# Technical Contract: `orbit activity:list`

## Activity Logging

This command does not emit an activity entry. Reason: read-only diagnostic.

## Doctor Relationship
MD,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('ignores commands that are not in the enforced allowlist', function (): void {
    $rule = new ActivityLoggingContractRule;
    $context = activityLoggingContractContext([
        '3_tool/1_tool-list/technical/1_tool-list.md' => <<<'MD'
# Technical Contract: `orbit tool:list`

**Owner:** `tool`.
MD,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('enforces node read command activity logging contracts', function (): void {
    $rule = new ActivityLoggingContractRule;
    $context = activityLoggingContractContext([
        '1_node/3_node-list/technical/1_node-list.md' => <<<'MD'
# Technical Contract: `orbit node:list`

**Owner:** `node`.
MD,
        '1_node/4_node-show/technical/1_node-show.md' => <<<'MD'
# Technical Contract: `orbit node:show [name]`

**Owner:** `node`.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(2);
});
