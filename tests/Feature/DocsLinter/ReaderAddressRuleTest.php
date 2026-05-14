<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\ReaderAddressRule;

/**
 * @param  array<string, string>  $files
 */
function readerAddressRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-reader-address-rule-'.bin2hex(random_bytes(6));

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

it('flags an Examples section on a command page that has neither you nor imperative', function (): void {
    $context = readerAddressRuleContext([
        'docs/commands/1_node/1_node-new/node-new.md' => <<<'MARKDOWN'
# node:new

## Examples

The command bootstraps the gateway runtime and installs the local node identity.
MARKDOWN,
    ]);

    expect((new ReaderAddressRule)->check($context))->toHaveCount(1);
});

it('accepts an Examples section that addresses you', function (): void {
    $context = readerAddressRuleContext([
        'docs/commands/1_node/1_node-new/node-new.md' => <<<'MARKDOWN'
# node:new

## Examples

You can bootstrap a gateway from your control machine with this command.
MARKDOWN,
    ]);

    expect((new ReaderAddressRule)->check($context))->toBe([]);
});

it('accepts an Examples section that opens with an imperative', function (): void {
    $context = readerAddressRuleContext([
        'docs/commands/1_node/1_node-new/node-new.md' => <<<'MARKDOWN'
# node:new

## Examples

Run `orbit node:new` on the host that will become the gateway.
MARKDOWN,
    ]);

    expect((new ReaderAddressRule)->check($context))->toBe([]);
});

it('does not lint technical contract files', function (): void {
    $context = readerAddressRuleContext([
        'docs/commands/1_node/1_node-new/technical/1_node-new.md' => <<<'MARKDOWN'
# Technical

## Examples

The contract bootstraps the gateway runtime.
MARKDOWN,
    ]);

    expect((new ReaderAddressRule)->check($context))->toBe([]);
});
