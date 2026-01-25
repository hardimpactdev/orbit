<?php

use App\Data\Install\InstallContext;

it('creates context with default values', function () {
    $context = InstallContext::fromOptions([]);

    expect($context->tld)->toBe('test');
    expect($context->phpVersions)->toBe(['8.4', '8.5']);
    expect($context->skipDocker)->toBeFalse();
    expect($context->skipTrust)->toBeFalse();
    expect($context->nonInteractive)->toBeFalse();
    expect($context->configDir)->toContain('/.config/orbit');
});

it('parses tld option', function () {
    $context = InstallContext::fromOptions(['tld' => 'local']);

    expect($context->tld)->toBe('local');
});

it('parses php-versions as comma-separated string', function () {
    $context = InstallContext::fromOptions(['php-versions' => '8.3,8.4,8.5']);

    expect($context->phpVersions)->toBe(['8.3', '8.4', '8.5']);
});

it('parses php-versions as array', function () {
    $context = InstallContext::fromOptions(['php-versions' => ['8.3', '8.4']]);

    expect($context->phpVersions)->toBe(['8.3', '8.4']);
});

it('normalizes php version without dots', function () {
    $context = InstallContext::fromOptions(['php-versions' => '84,85']);

    expect($context->phpVersions)->toBe(['8.4', '8.5']);
});

it('parses skip-docker option', function () {
    $context = InstallContext::fromOptions(['skip-docker' => true]);

    expect($context->skipDocker)->toBeTrue();
});

it('parses skip-trust option', function () {
    $context = InstallContext::fromOptions(['skip-trust' => true]);

    expect($context->skipTrust)->toBeTrue();
});

it('parses yes option as non-interactive', function () {
    $context = InstallContext::fromOptions(['yes' => true]);

    expect($context->nonInteractive)->toBeTrue();
});
