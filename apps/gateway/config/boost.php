<?php

declare(strict_types=1);

return [
    'enforce_tests' => true,

    'rules' => [
        'enabled' => true,
        'scoped_guidelines' => true,
    ],

    'guidelines' => [
        'exclude' => [
            'deployments',
            // Project context and conventions already live in AGENTS.md and scoped rules.
            'foundation',
            'spatie/guidelines-skills/core',
            // Inertia comes with the Horizon dashboard; the Gateway itself has no Inertia pages.
            'inertia-laravel/core',
            'inertia-laravel/v3',
        ],
    ],
    'skills' => [
        'exclude' => [
            'infer-conventions',
            'spatie-javascript',
            'spatie-version-control',
            'spatie-security',
            'spatie-laravel-php',
        ],
    ],
];
