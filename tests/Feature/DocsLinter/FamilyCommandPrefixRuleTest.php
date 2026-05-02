<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\FamilyCommandPrefixRule;

/**
 * @param  list<string>  $directories
 * @param  array<string, string>  $files
 */
function familyCommandPrefixContext(array $directories = [], array $files = []): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-family-command-prefix-'.bin2hex(random_bytes(6));
    $commandsRoot = "{$root}/docs/commands";

    foreach ($directories as $directory) {
        mkdir("{$commandsRoot}/{$directory}", recursive: true);
    }

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

it('flags commands that do not start with the non-operation family prefix', function (): void {
    $rule = new FamilyCommandPrefixRule;
    $context = familyCommandPrefixContext([
        '1_node/1_node-list',
        '1_node/2_gateway-add',
        '2_gateway/1_gateway-add',
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->path)->toEndWith('docs/commands/1_node/2_gateway-add')
        ->and($findings[0]->ruleId)->toBe('command_docs.family_command_prefix');
});

it('allows operation commands with arbitrary prefixes', function (): void {
    $rule = new FamilyCommandPrefixRule;
    $context = familyCommandPrefixContext([
        '10_operation/1_update',
        '10_operation/2_dns-list',
        '10_operation/3_doctor',
    ]);

    expect($rule->check($context))->toBe([]);
});

it('checks flat command files in family roots', function (): void {
    $rule = new FamilyCommandPrefixRule;
    $context = familyCommandPrefixContext(files: [
        '1_node/1_node-list.md' => '# Node List',
        '1_node/2_gateway-add.md' => '# Gateway Add',
        '1_node/README.md' => '# Node Commands',
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->path)->toEndWith('docs/commands/1_node/2_gateway-add.md')
        ->and($findings[0]->ruleId)->toBe('command_docs.family_command_prefix');
});
