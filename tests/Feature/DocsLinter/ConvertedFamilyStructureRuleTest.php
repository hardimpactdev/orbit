<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\ConvertedFamilyStructureRule;

/**
 * @param  array<string, string>  $files
 */
function convertedFamilyStructureContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-converted-family-structure-'.bin2hex(random_bytes(6));
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

it('flags flat command files in converted family roots', function (): void {
    $rule = new ConvertedFamilyStructureRule;
    $context = convertedFamilyStructureContext([
        '3_tool/README.md' => '# Tool Commands',
        '3_tool/tool-doctor.md' => '# Tool Doctor',
        '3_tool/1_tool-list.md' => '# Tool List',
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->path)->toEndWith('docs/commands/3_tool/1_tool-list.md')
        ->and($findings[0]->ruleId)->toBe('command_docs.converted_family_structure');
});

it('allows command directories and family-level markdown files', function (): void {
    $rule = new ConvertedFamilyStructureRule;
    $context = convertedFamilyStructureContext([
        '3_tool/README.md' => '# Tool Commands',
        '3_tool/tool-doctor.md' => '# Tool Doctor',
        '3_tool/tool-concepts.md' => '# Tool Concepts',
        '3_tool/1_tool-list/tool-list.md' => '# Tool List',
        '3_tool/1_tool-list/technical/1_tool-list.md' => '# Tool List Technical Contract',
    ]);

    expect($rule->check($context))->toBe([]);
});
