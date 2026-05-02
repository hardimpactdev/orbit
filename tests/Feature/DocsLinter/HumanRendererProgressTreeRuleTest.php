<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\HumanRendererProgressTreeRule;

/**
 * @param  array<string, string>  $files
 */
function humanRendererProgressTreeContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-human-progress-tree-'.bin2hex(random_bytes(6));
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

it('flags branch-style progress trees in human renderer docs', function (): void {
    $rule = new HumanRendererProgressTreeRule;
    $context = humanRendererProgressTreeContext([
        '4_firewall/2_firewall-allow/technical/6.1_firewall-allow_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

```text
Firewall allow local-vite on app-1
├─ Validate firewall target
└─ Enact backend firewall rule
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.human_progress_tree')
        ->and($findings[0]->message)->toContain('status-dot tree shape');
});

it('flags bracketed status progress trees in human renderer docs', function (): void {
    $rule = new HumanRendererProgressTreeRule;
    $context = humanRendererProgressTreeContext([
        '5_app/5_app-root/technical/6.1_app-root_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

```text
Updating document root for app 'docs'...
├── Updating gateway intent... [DONE]
└── Re-enacting node artifacts...
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.human_progress_tree')
        ->and($findings[0]->message)->toContain('[DONE]');
});

it('flags bracketed status-only progress examples in human renderer docs', function (): void {
    $rule = new HumanRendererProgressTreeRule;
    $context = humanRendererProgressTreeContext([
        '5_app/1_app-new/technical/6.1_app-new_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

```text
Creating app 'docs'...
  [DONE] Create app source
  [DONE] Write registry intent
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.human_progress_tree')
        ->and($findings[0]->message)->toContain('[DONE]');
});

it('flags implementation-shaped status-dot progress labels in human renderer docs', function (): void {
    $rule = new HumanRendererProgressTreeRule;
    $context = humanRendererProgressTreeContext([
        '14_php/2_php-use/technical/6.1_php-use_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

```text
┌ Select PHP Runtime
○ Resolve target
○ Write gateway intent
○ Enact runtime artifacts
└ PHP runtime updated
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.human_progress_tree')
        ->and($findings[0]->message)->toContain('product-level work');
});

it('requires the first progress tree to use the running lifecycle shape', function (): void {
    $rule = new HumanRendererProgressTreeRule;
    $context = humanRendererProgressTreeContext([
        '14_php/2_php-use/technical/6.1_php-use_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

```text
┌ Select PHP Runtime
○ Resolve target
○ Validate version
└ PHP runtime updated
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.human_progress_tree')
        ->and($findings[0]->message)->toContain('active `-ing` title');
});

it('requires the first progress tree footer to stay pending', function (): void {
    $rule = new HumanRendererProgressTreeRule;
    $context = humanRendererProgressTreeContext([
        '14_php/2_php-use/technical/6.1_php-use_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

```text
┌ Updating PHP runtime to PHP 8.5
○ Resolve target
○ Validate version
└ PHP runtime updated
```
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.human_progress_tree')
        ->and($findings[0]->message)->toContain('`└ Working...`');
});

it('allows status-dot progress trees and no-tree explanations', function (): void {
    $rule = new HumanRendererProgressTreeRule;
    $context = humanRendererProgressTreeContext([
        '1_node/1_node-new/technical/6.1_node-new_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

```text
┌ Enrolling Control Node
○ Validate node
○ Mint WireGuard peer
└ Working...

Next steps:
- Install the WireGuard configuration on the control node.
```
MD,
        '14_php/2_php-use/technical/6.1_php-use_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

```text
┌ Updating PHP runtime to PHP 8.5
○ Resolve target
○ Validate version
○ Apply and verify PHP change
└ Working...
```

```text
┌ Updating PHP runtime to PHP 8.5
● Resolved target
● Validated version
● Applied and verified PHP change
└ Successfully updated PHP runtime to PHP 8.5
```
MD,
        '3_tool/1_tool-list/technical/6.1_tool-list_output-render_human.md' => <<<'MD'
# Human Renderer

## Progress Tree

`tool-list` is a read-only gateway registry query. Execution is expected to
complete below one second and does not perform slow external work. No progress
tree is rendered.
MD,
    ]);

    expect($rule->check($context))->toBe([]);
});
