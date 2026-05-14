<?php

declare(strict_types=1);

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\Rules\CompoundNounStackRule;

/**
 * @param  array<string, string>  $files
 */
function compoundNounStackRuleContext(array $files): CommandDocsLintContext
{
    $root = sys_get_temp_dir().'/orbit-compound-noun-stack-rule-'.bin2hex(random_bytes(6));

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

it('flags an invented hyphenated compound followed by 2+ modifiers and a head noun', function (): void {
    $context = compoundNounStackRuleContext([
        'docs/notes.md' => 'The gateway-owned development DNS mapping is created during provisioning.',
    ]);

    $findings = (new CompoundNounStackRule)->check($context);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->message)->toContain('gateway-owned development DNS mapping');
});

it('does not flag a hyphenated compound followed by one short modifier', function (): void {
    $context = compoundNounStackRuleContext([
        'docs/notes.md' => 'The gateway-tracked configuration drives every render.',
    ]);

    expect((new CompoundNounStackRule)->check($context))->toBe([]);
});

it('does not flag an accepted technical compound', function (): void {
    $context = compoundNounStackRuleContext([
        'docs/notes.md' => 'The PHP-FPM pool configuration is renderered by the gateway.',
    ]);

    expect((new CompoundNounStackRule)->check($context))->toBe([]);
});

it('breaks the chain on sentence punctuation so cross-sentence chains do not match', function (): void {
    $context = compoundNounStackRuleContext([
        'docs/notes.md' => 'The gateway-tracked configuration drives renders. The rendered artifact is stored on the node.',
    ]);

    expect((new CompoundNounStackRule)->check($context))->toBe([]);
});

it('strips markdown links so reference URLs do not pollute the chain', function (): void {
    $context = compoundNounStackRuleContext([
        'docs/notes.md' => 'See [Architecture](ARCHITECTURE.md#state-model) for the gateway-tracked configuration model.',
    ]);

    expect((new CompoundNounStackRule)->check($context))->toBe([]);
});
