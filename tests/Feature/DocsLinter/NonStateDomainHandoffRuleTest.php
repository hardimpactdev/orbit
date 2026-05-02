<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\NonStateDomainHandoffRule;

/**
 * @param  array<string, string>  $files
 */
function nonStateDomainHandoffContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-non-state-domain-handoff-'.bin2hex(random_bytes(6));
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

it('requires non-state command domains to declare state ownership handoffs', function (): void {
    $rule = new NonStateDomainHandoffRule;
    $context = nonStateDomainHandoffContext([
        '10_deploy/README.md' => '# Deploy Commands',
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->path)->toEndWith('docs/commands/10_deploy/README.md')
        ->and($findings[0]->ruleId)->toBe('command_docs.non_state_domain_handoff');
});

it('requires the state ownership section to name the state family doctor handoff', function (): void {
    $rule = new NonStateDomainHandoffRule;
    $context = nonStateDomainHandoffContext([
        '10_deploy/README.md' => <<<'MD'
# Deploy Commands

## State Ownership

The deploy command domain does not own a state family.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('doctor --family=app');
});

it('allows registered non-state command domains with explicit handoffs', function (): void {
    $rule = new NonStateDomainHandoffRule;
    $context = nonStateDomainHandoffContext([
        '10_deploy/README.md' => <<<'MD'
# Deploy Commands

## State Ownership

The deploy command domain does not own a state family.
`doctor --family=app` owns deployment health.
MD,
    ]);

    expect($rule->check($context))->toBe([]);
});

it('requires Cloudflare provider utility docs to hand off to proxy and app doctors', function (): void {
    $rule = new NonStateDomainHandoffRule;
    $context = nonStateDomainHandoffContext([
        '12_cf/README.md' => <<<'MD'
# Cloudflare Commands

## State Ownership

The cf command domain does not own a state family.
`doctor --family=proxy` owns ingress route health.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('doctor --family=app');
});

it('requires VPN administration docs to hand off to node doctor', function (): void {
    $rule = new NonStateDomainHandoffRule;
    $context = nonStateDomainHandoffContext([
        '13_vpn/README.md' => <<<'MD'
# VPN Commands

## State Ownership

The vpn command domain does not own a state family.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('doctor --family=node');
});

it('requires PHP runtime docs to hand off to runtime-owning state doctors', function (): void {
    $rule = new NonStateDomainHandoffRule;
    $context = nonStateDomainHandoffContext([
        '14_php/README.md' => <<<'MD'
# PHP Runtime Commands

## State Ownership

The php command domain does not own a state family.
`doctor --family=tool` owns PHP runtime installation.
`doctor --family=app` owns app PHP-FPM health.
`doctor --family=workspace` owns workspace PHP-FPM health.
`doctor --family=node` owns node CLI PHP defaults.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('doctor --family=proxy');
});

it('requires Agent IDE docs to hand off to adapter-consuming state doctors', function (): void {
    $rule = new NonStateDomainHandoffRule;
    $context = nonStateDomainHandoffContext([
        '15_agent-ide/README.md' => <<<'MD'
# Agent IDE Commands

## State Ownership

The agent-ide command domain does not own a state family.
`doctor --family=node` owns node defaults.
`doctor --family=app` owns app settings.
`doctor --family=workspace` owns workspace state.
`doctor --family=process` owns crash events.
MD,
    ]);

    $findings = $rule->check($context);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('doctor --family=tool');
});

it('ignores state-family command domains', function (): void {
    $rule = new NonStateDomainHandoffRule;
    $context = nonStateDomainHandoffContext([
        '5_app/README.md' => '# App Commands',
    ]);

    expect($rule->check($context))->toBe([]);
});
