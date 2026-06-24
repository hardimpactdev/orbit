<?php

declare(strict_types=1);

return [
    /*
     |--------------------------------------------------------------------------
     | Boost Master Switch
     |--------------------------------------------------------------------------
     */

    'enabled' => env('BOOST_ENABLED', true),

    /*
     |--------------------------------------------------------------------------
     | Boost Browser Logs Watcher
     |--------------------------------------------------------------------------
     */

    'browser_logs_watcher' => env('BOOST_BROWSER_LOGS_WATCHER', true),

    /*
     |--------------------------------------------------------------------------
     | Boost Executables Paths
     |--------------------------------------------------------------------------
     |
     | Orbit installs Boost in apps/gateway but keeps generated agent artifacts
     | at the monorepo root. current_directory makes MCP launches run from the
     | repository root instead of the gateway app directory.
     |
     */

    'executable_paths' => [
        'php' => env('BOOST_PHP_EXECUTABLE_PATH'),
        'composer' => env('BOOST_COMPOSER_EXECUTABLE_PATH'),
        'npm' => env('BOOST_NPM_EXECUTABLE_PATH'),
        'vendor_bin' => env('BOOST_VENDOR_BIN_EXECUTABLE_PATH'),
        'current_directory' => env('BOOST_CURRENT_DIRECTORY_EXECUTABLE_PATH', repo_path()),
    ],

    /*
     |--------------------------------------------------------------------------
     | Agent Artifact Paths
     |--------------------------------------------------------------------------
     |
     | Override Boost defaults so guidelines, MCP configs, and skills land at
     | the monorepo root rather than under apps/gateway.
     |
     */

    'agents' => [
        'codex' => [
            'guidelines_path' => repo_path('AGENTS.md'),
            'mcp_config_path' => repo_path('.codex/config.toml'),
            'skills_path' => '../../.agents/skills',
        ],
        'claude_code' => [
            'guidelines_path' => repo_path('CLAUDE.md'),
            'mcp_config_path' => repo_path('.mcp.json'),
            'skills_path' => '../../.agents/skills',
        ],
    ],
];
