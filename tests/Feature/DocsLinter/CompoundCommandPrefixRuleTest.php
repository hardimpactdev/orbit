<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\CompoundCommandPrefixRule;

/**
 * @param  array<string, string>  $files
 */
function compoundCommandPrefixContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-compound-command-prefix-'.bin2hex(random_bytes(6));
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

it('flags split vpn compound command prefixes', function (): void {
    $rule = new CompoundCommandPrefixRule;
    $context = compoundCommandPrefixContext([
        '13_vpn/README.md' => <<<'MD'
# VPN Commands

Use `orbit vpn:client-list` and `orbit vpn:web-ui-change-password`.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('command_docs.compound_command_prefix')
        ->and($findings[0]->message)->toContain('vpn-client:list')
        ->and($findings[1]->message)->toContain('vpn-web-ui:change-password');
});

it('flags split cloudflare and workspace compound command prefixes', function (): void {
    $rule = new CompoundCommandPrefixRule;
    $context = compoundCommandPrefixContext([
        '12_cf/README.md' => <<<'MD'
# Cloudflare Commands

Use `orbit cf:dns-list` and `orbit cf:cache-rule-add`.
MD,
        '6_workspace/README.md' => <<<'MD'
# Workspace Commands

Use `orbit workspace:setup-step-add` and `orbit workspace:teardown-step-add`.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(4)
        ->and($findings[0]->message)->toContain('cf-dns:list')
        ->and($findings[1]->message)->toContain('cf-cache-rule:add')
        ->and($findings[2]->message)->toContain('workspace-setup-step:add')
        ->and($findings[3]->message)->toContain('workspace-teardown-step:add');
});

it('allows compound command prefixes before the colon', function (): void {
    $rule = new CompoundCommandPrefixRule;
    $context = compoundCommandPrefixContext([
        'README.md' => <<<'MD'
# Command Contracts

Use `orbit vpn-client:list`, `orbit vpn-web-ui:change-password`,
`orbit cf-dns:list`, `orbit cf-cache-rule:add`,
`orbit workspace-setup-step:add`, and `orbit workspace-teardown-step:add`.
MD,
    ]);

    expect($rule->check($context))->toBe([]);
});
