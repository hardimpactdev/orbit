<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\CommandDirectoryStructureRule;

/**
 * @param  array<string, string>  $files
 */
function commandDirectoryStructureContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-command-directory-structure-'.bin2hex(random_bytes(6));
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

it('requires renderer contracts in command directories', function (): void {
    $rule = new CommandDirectoryStructureRule;
    $context = commandDirectoryStructureContext([
        '3_tool/1_tool-list/tool-list.md' => '# Tool List',
        '3_tool/1_tool-list/technical/1_tool-list.md' => '# Technical Contract',
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('command_docs.command_directory_structure')
        ->and($findings[0]->message)->toContain('6.1_tool-list_output-render_human.md')
        ->and($findings[1]->message)->toContain('6.2_tool-list_output-render_json.md');
});

it('allows the required command directory structure', function (): void {
    $rule = new CommandDirectoryStructureRule;
    $context = commandDirectoryStructureContext([
        '3_tool/1_tool-list/tool-list.md' => '# Tool List',
        '3_tool/1_tool-list/technical/1_tool-list.md' => '# Technical Contract',
        '3_tool/1_tool-list/technical/6.1_tool-list_output-render_human.md' => '# Human Renderer',
        '3_tool/1_tool-list/technical/6.2_tool-list_output-render_json.md' => '# JSON Renderer',
    ]);

    expect($rule->check($context))->toBe([]);
});
