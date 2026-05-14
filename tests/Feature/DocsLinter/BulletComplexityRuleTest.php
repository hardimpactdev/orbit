<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\BulletComplexityRule;

/**
 * @param  array<string, string>  $files
 */
function bulletComplexityRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-bullet-complexity-rule-'.bin2hex(random_bytes(6));

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

it('flags a long multi-clause bullet that reads as a mini paragraph', function (): void {
    $context = bulletComplexityRuleContext([
        'docs/notes.md' => "# Notes\n\n- The installer prepares the host, installs PHP and the required extensions, links the executable, and then bootstraps the gateway runtime so the local node can serve requests.\n",
    ]);

    expect((new BulletComplexityRule)->check($context))->not->toBeEmpty();
});

it('does not flag a short single-clause bullet', function (): void {
    $context = bulletComplexityRuleContext([
        'docs/notes.md' => "# Notes\n\n- The installer prepares the host.\n",
    ]);

    expect((new BulletComplexityRule)->check($context))->toBe([]);
});

it('flags more than eight consecutive bullets in reader-facing docs', function (): void {
    $context = bulletComplexityRuleContext([
        'docs/notes.md' => "# Notes\n\n## Section\n\n".str_repeat("- short item\n", 10),
    ]);

    expect((new BulletComplexityRule)->check($context))->not->toBeEmpty();
});

it('does not fire the consecutive-bullet rule on technical contracts', function (): void {
    $context = bulletComplexityRuleContext([
        'docs/commands/1_node/1_node-new/technical/1_node-new.md' => "# Technical\n\n## Section\n\n".str_repeat("- short item\n", 12),
    ]);

    expect((new BulletComplexityRule)->check($context))->toBe([]);
});
