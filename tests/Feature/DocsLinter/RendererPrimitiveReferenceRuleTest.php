<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\RendererPrimitiveReferenceRule;

function rendererPrimitiveContext(array $files): CommandDocsLintContext
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

it('passes when a human renderer links a primitive in the Primitive section', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '6.1_node-sample_output-render_human.md' => <<<'MARKDOWN'
# Human Renderer: `orbit node:sample`

## Primitive

- Output table: [`Laravel\Prompts\table`](../../../../ux/lists/table.md)

## Output

```text
ROLE NAME
app  app-1
```
MARKDOWN,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('passes when a human renderer explicitly declares "None." with a reason', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '6.1_node-sample_output-render_human.md' => <<<'MARKDOWN'
# Human Renderer: `orbit node:sample`

## Primitive

None. Renderer prints a single line via `$this->line(...)`.
MARKDOWN,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('flags a human renderer that is missing the Primitive section', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '6.1_node-sample_output-render_human.md' => <<<'MARKDOWN'
# Human Renderer: `orbit node:sample`

## Output

Plain output.
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('command_docs.renderer_primitive_reference')
        ->and($findings[0]->message)->toContain('## Primitive');
});

it('flags a human renderer whose Primitive section has no link or None declaration', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '6.1_node-sample_output-render_human.md' => <<<'MARKDOWN'
# Human Renderer: `orbit node:sample`

## Primitive

This command renders a table.

## Output
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('link to a primitive page');
});

it('passes when a JSON renderer declares "None." for the primitive', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '6.2_node-sample_output-render_json.md' => <<<'MARKDOWN'
# JSON Renderer: `orbit node:sample`

## Primitive

None. JSON renderer emits the standard envelope only.

## Envelope

Standard.
MARKDOWN,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('passes when an input-mode doc links every primitive named in its Prompt Mapping table', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '5.1_node-sample_input-mode_interactive.md' => <<<'MARKDOWN'
# Interactive Input Mode: `orbit node:sample`

## Prompt Mapping

### `node_sample.name`

| Property | Value |
| --- | --- |
| Primitive | `text` |
| Label | `Node name` |

### `node_sample.role`

| Property | Value |
| --- | --- |
| Primitive | `select` |
| Label | `Role` |

## Primitive References

- [`text`](../../../../ux/inputs/text-prompt.md)
- [`select`](../../../../ux/inputs/select-prompt.md)
MARKDOWN,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('flags an input-mode doc when a named primitive is not linked', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '5.1_node-sample_input-mode_interactive.md' => <<<'MARKDOWN'
# Interactive Input Mode: `orbit node:sample`

## Prompt Mapping

### `node_sample.name`

| Property | Value |
| --- | --- |
| Primitive | `text` |
| Label | `Node name` |
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('"text"')
        ->and($findings[0]->message)->toContain('inputs/text-prompt.md');
});

it('parses primitives from multi-column Prompt Mapping tables', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '5.1_node-sample_input-mode_interactive.md' => <<<'MARKDOWN'
# Interactive Input Mode: `orbit node:sample`

## Prompt Mapping

| Prompt id | Field | Primitive | Label |
| --- | --- | --- | --- |
| `node_sample.app` | `target` | `datatable` | `Select an app` |
| `node_sample.uri` | `uri` | `text` | `Request URI` |

## Primitive References

- [`datatable`](../../../../ux/lists/data-table-prompt.md)
- [`text`](../../../../ux/inputs/text-prompt.md)
MARKDOWN,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('flags an unknown primitive name in the Prompt Mapping table', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '5.1_node-sample_input-mode_interactive.md' => <<<'MARKDOWN'
# Interactive Input Mode: `orbit node:sample`

## Prompt Mapping

### `node_sample.weird`

| Property | Value |
| --- | --- |
| Primitive | `wizard` |
| Label | `Weird` |
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('Unknown prompt primitive "wizard"');
});

it('flags references to banned Symfony Console methods in renderer docs', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '6.1_node-sample_output-render_human.md' => <<<'MARKDOWN'
# Human Renderer: `orbit node:sample`

## Primitive

- Output table: [`Laravel\Prompts\table`](../../../../ux/lists/table.md)

## Output

The renderer calls `$this->table(...)` to render the list.
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$this->table');
});

it('flags references to banned Symfony Console methods in input-mode docs', function (): void {
    $rule = new RendererPrimitiveReferenceRule;
    $context = rendererPrimitiveContext([
        '5.1_node-sample_input-mode_interactive.md' => <<<'MARKDOWN'
# Interactive Input Mode: `orbit node:sample`

## Prompt Mapping

### `node_sample.proceed`

| Property | Value |
| --- | --- |
| Primitive | `confirm` |
| Label | `Proceed` |

[`confirm`](../../../../ux/inputs/confirm-prompt.md)

## Implementation

Calls `$this->confirm(...)` for the prompt.
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('$this->confirm');
});
