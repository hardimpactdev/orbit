<?php

declare(strict_types=1);

$implementerEffort = env('ORBIT_TASKS_IMPLEMENTER_EFFORT');
$reviewerEffort = env('ORBIT_TASKS_REVIEWER_EFFORT');
$configuredHome = env('ORBIT_HOME');
$userHome = getenv('HOME');
$orbitHome = base_path('.orbit');

if (is_string($userHome) && $userHome !== '') {
    $orbitHome = $userHome.'/.orbit';
}

if (is_string($configuredHome) && $configuredHome !== '') {
    $orbitHome = $configuredHome;
}

// A path relative to the Gateway checkout, or an absolute one.
$gatewayPath = static fn (string $path): string => str_starts_with($path, '/') ? $path : base_path($path);

return [
    'recipe_snapshot_cache' => env('ORBIT_RECIPE_SNAPSHOT_CACHE'),
    // The pinned agent and its command contract (docs/reference/node-agent.md#commands).
    'agent' => [
        'contract' => $gatewayPath((string) env('ORBIT_AGENT_CONTRACT', 'resources/agent/frozen-commands.json')),
        // The release checkout holds the manifest CI wrote with the tested artifacts; it is not tracked in Git.
        'delivery' => $gatewayPath((string) env('ORBIT_AGENT_DELIVERY', '../agent/delivery/manifest.json')),
    ],
    'home' => rtrim(string: $orbitHome, characters: '/'),
    'gateway_checkout' => rtrim(
        string: env(key: 'ORBIT_GATEWAY_CHECKOUT', default: '/home/orbit/orbit/apps/gateway'),
        characters: '/',
    ),
    // The Gateway queue (docs/reference/gateway-queue.md): the slug of a Redis Database connection, and its database index.
    'gateway_queue' => [
        'connection' => (string) env('ORBIT_GATEWAY_QUEUE_CONNECTION', ''),
        'redis_database' => (int) env('ORBIT_GATEWAY_QUEUE_REDIS_DATABASE', 1),
    ],
    // Gateway release limits (docs/reference/gateway-recovery.md#release-layout).
    'gateway_releases' => [
        // The most releases deploy keeps, the current and the previous one included; at least 2, so the way back stays.
        'keep' => (int) env('ORBIT_GATEWAY_RELEASES_KEEP', 3),
        // Automatic releases ship the newest commit of this branch whose check run of this name passed.
        // The repository is the origin of the shared release repository.
        'branch' => env(key: 'ORBIT_GATEWAY_RELEASE_BRANCH', default: 'main'),
        'check' => env(key: 'ORBIT_GATEWAY_RELEASE_CHECK', default: 'Required checks'),
    ],
    'gateway_web' => rtrim(
        string: env(key: 'ORBIT_GATEWAY_WEB', default: '/home/orbit/web'),
        characters: '/',
    ),
    // Where the Gateway finds the CLI release of its own commit (ADR 0202): the public repository whose CI publishes
    // `cli-v0.N.0`, and the Git checkout whose history counts N. The checkout is the running Gateway's own.
    'cli_releases' => [
        'repository' => env(key: 'ORBIT_CLI_RELEASE_REPOSITORY', default: 'https://github.com/hardimpactdev/orbit'),
        'git_directory' => base_path(),
        // The Sigstore trust root that release provenance is verified against. Override it only to recover from a
        // key rotation the committed root does not hold yet (docs/reference/gateway-recovery.md#release-provenance).
        'trusted_root' => trim((string) env('ORBIT_CLI_RELEASE_TRUSTED_ROOT', '')) ?: resource_path('sigstore/trusted_root.json'),
    ],
    // PHP-FPM and Caddy end a Gateway request after 600 seconds. The command deadline ends remote
    // work 30 seconds earlier, so a slow command fails with an error and records its Activity.
    'command_timeout' => 570.0,
    // PHP-FPM checks its 600-second request limit on a heartbeat of a third of it, so a Gateway request
    // ends by about 800 seconds. An Activity still `running` at 900
    // seconds belongs to a request that was killed.
    'activity_interrupted_after' => 900,
    'websocket' => [
        'repository' => env(key: 'ORBIT_WEBSOCKET_REPOSITORY', default: 'https://github.com/hardimpactdev/orbit-reverb.git'),
        // Role convergence installs this Gateway release's server, never a moving branch.
        'ref' => '713bceace83d314cbafc707c341e4f8d5e19c3aa',
        'install_path' => env(key: 'ORBIT_WEBSOCKET_INSTALL_PATH', default: '/opt/orbit/websocket'),
        'port' => max(1, (int) env('ORBIT_WEBSOCKET_PORT', 8790)),
    ],
    'analytics' => [
        // The Plausible Community Edition release a new analytics role runs; `analytics:update` pins another.
        'plausible_version' => env(key: 'ORBIT_ANALYTICS_PLAUSIBLE_VERSION', default: '3.2.1'),
    ],
    'git' => [
        // Transports a credentialed development checkout fetch may use. Tests add `file` for local origins.
        'checkout_protocols' => ['https'],
    ],
    'pi' => [
        'port' => max(1, (int) env('ORBIT_PI_PORT', 3774)),
    ],
    'tasks' => [
        // Operator-owned repair trust: github.com owner/repository and positive integer account IDs, as
        // `owner/repo:id,id;owner/repo:id`. Empty disables review feedback. No logins, wildcards, roles, or
        // branch-provided settings. TaskReviewTrust reads it.
        'github_reviewers' => (string) env('ORBIT_TASKS_GITHUB_REVIEWERS', ''),
        // ADR 0203: numeric GitHub account IDs whose pull requests Orbit reviews and merges, per repository, in the
        // same form.
        'pull_request_authors' => (string) env('ORBIT_TASKS_PULL_REQUEST_AUTHORS', ''),
        // Comma-separated GitHub logins requested as reviewers after a task pull request is opened or reused.
        // Unset or empty requests no one. The pull request author is omitted at request time.
        'review_request_logins' => (string) env('ORBIT_TASKS_REVIEW_REQUEST_LOGINS', ''),
        'implementer_agent_driver' => env('ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER', 'pi'),
        'reviewer_agent_driver' => env('ORBIT_TASKS_REVIEWER_AGENT_DRIVER', 'pi'),
        // Models for new groups. Unset keeps TaskAgentDefaults.
        'implementer_model' => env('ORBIT_TASKS_IMPLEMENTER_MODEL'),
        'reviewer_model' => env('ORBIT_TASKS_REVIEWER_MODEL'),
        // Effort for new threads, on a task VM and in a task copy. Existing threads keep their stored value.
        'implementer_effort' => $implementerEffort === null || $implementerEffort === '' ? 'medium' : $implementerEffort,
        'reviewer_effort' => $reviewerEffort === null || $reviewerEffort === '' ? 'high' : $reviewerEffort,
        // The Claude model of a new thread in a task copy, per role. A copy reaches Claude Code only. The role's effort above picks
        // the T3 provider instance the thread starts on.
        'copy_turn_models' => [
            'implementer' => (string) env('ORBIT_TASKS_COPY_IMPLEMENTER_MODEL', 'claude-opus-5-5'),
            'reviewer' => (string) env('ORBIT_TASKS_COPY_REVIEWER_MODEL', 'claude-opus-5-5'),
        ],
        // A group reserved longer than this returns to todo on the next tick. Keep it well above the slowest workspace provision.
        'reserved_timeout_seconds' => max(60, (int) env('ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS', 3600)),
        // How many task copies (docs/cli/task/start.mmd) one Node holds at once, counted at each task start. Set in the Gateway's .env.
        'copies_max' => max(0, (int) env('ORBIT_TASKS_COPIES_MAX', 1)),
        // The Projects, besides nested-fleet trial Projects, whose task copies may publish (docs/reference/tasks.md#task-copy): a JSON list
        // of positive integer Project ids. An operator adds a Project once the publish guard for .env content is live. Malformed JSON or a
        // JSON object stays as decoded and refuses every Project but a nested-fleet one.
        'copy_publish_projects' => json_decode((string) (env('ORBIT_TASKS_COPY_PUBLISH_PROJECTS') ?: '[]')),
    ],
    // Release alerts always write Activity and a problem. The signed webhook is skipped unless both values are set.
    'releases' => [
        'alert_webhook_url' => env('ORBIT_RELEASE_ALERT_WEBHOOK_URL'),
        'alert_webhook_secret' => env('ORBIT_RELEASE_ALERT_WEBHOOK_SECRET'),
    ],
    // The outer loop neither counts nor files a listed fingerprint or a source path under a prefix.
    'problems' => [
        'suppressed_fingerprints' => [],
        'suppressed_path_prefixes' => [],
    ],
    'hibernation' => [
        'idle_seconds' => max(1, (int) env('ORBIT_HIBERNATION_IDLE_SECONDS', 3600)),
        'sweep_seconds' => max(60, (int) env('ORBIT_HIBERNATION_SWEEP_SECONDS', 600)),
        'wake_timeout_seconds' => max(5, (int) env('ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS', 60)),
        'dependency_idle_seconds' => max(1, (int) env('ORBIT_HIBERNATION_DEPENDENCY_IDLE_SECONDS', 604800)),
        'cold_wake_timeout_seconds' => max(60, (int) env('ORBIT_HIBERNATION_COLD_WAKE_TIMEOUT_SECONDS', 1800)),
        // The idle sleep of development containers: off until the operator turns it on. The dry run reads it either way.
        'container_sleep' => (bool) env('ORBIT_HIBERNATION_CONTAINER_SLEEP', false),
        // Instance ids the idle sleep never puts to sleep, comma-separated.
        'container_never_sleep' => array_values(array_filter(array_map('intval', explode(',', (string) env('ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP', ''))), static fn (int $id): bool => $id > 0)),
    ],
    // The Conn thread index (docs/reference/conn.md#thread-index): its reads of container T3 servers, the minute poll, the list's
    // read-through and the last read before a sleep, are off until the operator turns them on. The routes and write-through always run.
    'conn' => [
        'thread_index_poll' => (bool) env('ORBIT_CONN_THREAD_INDEX_POLL', false),
        // The held sessions a new clone's server gets for the creating profile's Nodes before their first read
        // (docs/reference/conn.md#held-sessions): off until the operator turns it on. The session handle, renewal and revoke always run.
        'held_session_premint' => (bool) env('ORBIT_CONN_HELD_SESSION_PREMINT', false),
    ],
];
