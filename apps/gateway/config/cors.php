<?php

declare(strict_types=1);

/**
 * Disable Laravel's global wildcard CORS for the gateway API.
 *
 * Browser process list/lifecycle access uses ProcessBrowserCors for Origin
 * admission only (registered app/workspace proxy domain matching the requested
 * `app`). CORS never authenticates callers. Auth remains peer source IP + grant
 * + permission, same as CLI. Non-browser CLI/node clients do not need CORS.
 */
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
