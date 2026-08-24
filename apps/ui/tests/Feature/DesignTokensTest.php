<?php

declare(strict_types=1);

test('glow token explicitly consumes the accent-glow color variable', function () {
    $themeCss = file_get_contents(resource_path('css/theme.css'));
    expect($themeCss)->not()->toBeFalse();

    // Verify --glow-accent is constructed from var(--accent-glow) rather than hardcoded colors
    expect($themeCss)->toMatch('/--glow-accent:\s*0\s+0\s+\d+px\s+var\(--accent-glow\);/');
});

test('reduced motion styles disable animations and transitions', function () {
    $themeCss = file_get_contents(resource_path('css/theme.css'));
    expect($themeCss)->not()->toBeFalse();

    expect($themeCss)
        ->toMatch('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[\s\S]*animation-duration:\s*0\.01ms\s*!important;/')
        ->toMatch('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[\s\S]*transition-duration:\s*0\.01ms\s*!important;/')
        ->toMatch('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{[\s\S]*scroll-behavior:\s*auto\s*!important;/');
});

test('noscrollbar utility disables scrollbars across browser engines', function () {
    $themeCss = file_get_contents(resource_path('css/theme.css'));
    expect($themeCss)->not()->toBeFalse();

    expect($themeCss)
        ->toMatch('/\.noscrollbar::-webkit-scrollbar\s*\{[\s\S]*display:\s*none;/')
        ->toMatch('/\.noscrollbar\s*\{[\s\S]*scrollbar-width:\s*none;/');
});
