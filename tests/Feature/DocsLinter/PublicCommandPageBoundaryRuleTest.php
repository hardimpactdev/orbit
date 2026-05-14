<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\PublicCommandPageBoundaryRule;

function publicCommandPageBoundaryContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-docs-linter-'.bin2hex(random_bytes(6));
    $directory = "{$root}/docs/commands/1_node/1_node-sample";

    mkdir($directory, recursive: true);

    foreach ($files as $name => $contents) {
        file_put_contents("{$directory}/{$name}", $contents);
    }

    return new CommandDocsLintContext(
        repositoryRoot: $root,
        scanRoot: "{$root}/docs/commands",
    );
}

it('allows operator-level output summaries and renderer contract links', function (): void {
    $rule = new PublicCommandPageBoundaryRule;
    $context = publicCommandPageBoundaryContext([
        'node-sample.md' => <<<'MARKDOWN'
# `orbit node:sample`

## Usage

```bash
orbit node:sample [--json]
```

## Output

Run without `--json` to see progress and a final summary.

Use `--json` for machine-readable output. See the
[JSON renderer contract](technical/6.2_node-sample_output-render_json.md) for
the exact shape.
MARKDOWN,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('flags JSON field paths and envelope details in public command pages', function (): void {
    $rule = new PublicCommandPageBoundaryRule;
    $context = publicCommandPageBoundaryContext([
        'node-sample.md' => <<<'MARKDOWN'
# `orbit node:sample`

## Output

JSON output returns `success.data.node` in the shared JSON command envelope.
Failures include `error.code=validation_failed`.
MARKDOWN,
    ]);

    $findings = $rule->check($context);
    $messages = array_map(
        fn ($finding): string => $finding->message,
        $findings,
    );

    expect($findings)->toHaveCount(3)
        ->and($messages)->toContain('Public command pages must not document JSON envelope details. Move exact JSON envelope behavior to the `6.2` renderer contract.')
        ->and(implode("\n", $messages))->toContain('JSON field paths');
});

it('flags exit-status policy and named renderer primitives in public command pages', function (): void {
    $rule = new PublicCommandPageBoundaryRule;
    $context = publicCommandPageBoundaryContext([
        'node-sample.md' => <<<'MARKDOWN'
# `orbit node:sample`

## Output

Human output renders a progress tree.
The empty result exits zero.
MARKDOWN,
    ]);

    $findings = $rule->check($context);
    $messages = array_map(
        fn ($finding): string => $finding->message,
        $findings,
    );

    expect($findings)->toHaveCount(2)
        ->and(implode("\n", $messages))->toContain('renderer primitives')
        ->and(implode("\n", $messages))->toContain('Exit-status');
});

it('flags exact progress tree examples in public command pages', function (): void {
    $rule = new PublicCommandPageBoundaryRule;
    $context = publicCommandPageBoundaryContext([
        'node-sample.md' => <<<'MARKDOWN'
# `orbit node:sample`

## Output

```text
┌ Updating node
○ Pulling source
└ Done
```
MARKDOWN,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(3)
        ->and(implode("\n", array_map(
            fn ($finding): string => $finding->message,
            $findings,
        )))->toContain('renderer primitives');
});
