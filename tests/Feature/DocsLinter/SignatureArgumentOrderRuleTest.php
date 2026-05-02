<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\SignatureArgumentOrderRule;

/**
 * @param  array<string, string>  $files
 */
function signatureArgumentOrderContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-signature-argument-order-'.bin2hex(random_bytes(6));
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

it('keeps optional arguments before required flags', function (): void {
    $rule = new SignatureArgumentOrderRule;
    $context = signatureArgumentOrderContext([
        '4_firewall/2_firewall-allow/technical/1_firewall-allow.md' => <<<'MD'
# Technical Contract: `orbit firewall:allow`

## Signature

```bash
orbit firewall:allow [name] [--node=<node>] --port=<port> [--json]
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.signature_argument_order')
        ->and($findings[0]->message)->toContain('orbit firewall:allow [name] --port=<port> [--node=<node>] [--json]');
});

it('flags shared target flags in the wrong specificity order', function (): void {
    $rule = new SignatureArgumentOrderRule;
    $context = signatureArgumentOrderContext([
        '3_tool/1_tool-list/technical/1_tool-list.md' => <<<'MD'
# Technical Contract: `orbit tool:list`

## Signature

```bash
orbit tool:list [--node=<node>] [--app=<app>] [--json]
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('orbit tool:list [--app=<app>] [--node=<node>] [--json]');
});

it('keeps json as the last optional flag', function (): void {
    $rule = new SignatureArgumentOrderRule;
    $context = signatureArgumentOrderContext([
        '6_workspace/6_workspace-history/technical/1_workspace-history.md' => <<<'MD'
# Technical Contract: `orbit workspace:history`

## Signature

```bash
orbit workspace:history [name] [--app=<app>] [--json] [--limit=<int>]
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('orbit workspace:history [name] [--app=<app>] [--limit=<int>] [--json]');
});

it('allows signatures in canonical order', function (): void {
    $rule = new SignatureArgumentOrderRule;
    $context = signatureArgumentOrderContext([
        '9_schedule/1_schedule-add/technical/1_schedule-add.md' => <<<'MD'
# Technical Contract: `orbit schedule:add`

## Signature

```bash
orbit schedule:add [name] (--command=<command>|--script=<path>) --interval=<expression> [--app=<app>|--node=<node>] [--timezone=<timezone>] [--json]
```
MD,
    ]);

    expect($rule->check($context))->toBe([]);
});
