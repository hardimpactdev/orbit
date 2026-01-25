<?php

/**
 * E2E Desktop Flow Test
 *
 * This test replicates the desktop app workflow:
 * 1. Create a project via API (dispatches CreateProjectJob)
 * 2. Track provisioning status via log files until "ready"
 * 3. Validate migrations ran (check database tables)
 * 4. Visit the project URL and verify it loads
 * 5. Check Laravel logs for errors
 * 6. Delete the project via API (dispatches DeleteProjectJob)
 * 7. Track deletion status via log files until "deleted"
 *
 * Usage:
 *   php tests/e2e-desktop-flow-test.php           # Full test (create + delete)
 *   php tests/e2e-desktop-flow-test.php --keep    # Create only, skip deletion
 *   TLD=test php tests/e2e-desktop-flow-test.php  # Use different TLD
 */

require __DIR__.'/../vendor/autoload.php';

$keepProject = in_array('--keep', $argv);
$tld = getenv('TLD') ?: 'ccc';
$slug = 'e2e-desktop-'.time();
$apiBase = "https://orbit.{$tld}/api";

$provisionEvents = [];
$deletionEvents = [];
$errors = [];

echo "=== E2E Desktop Flow Test ===\n";
echo "Slug: {$slug}\n";
echo "API: {$apiBase}\n";
echo "Template: hardimpactdev/liftoff-starterkit\n\n";

// Verify Reverb is accessible
$pusher = new Pusher\Pusher(
    'orbit-key',
    'orbit-secret',
    'orbit',
    ['host' => "reverb.{$tld}", 'port' => 443, 'scheme' => 'https', 'useTLS' => true, 'cluster' => 'mt1']
);

echo "[1/9] Testing Reverb connection...\n";
try {
    $pusher->getChannels();
    echo "  OK - Reverb is accessible\n\n";
} catch (Exception $e) {
    echo '  FAIL: '.$e->getMessage()."\n";
    echo "  Make sure orbit-reverb container is running\n";
    exit(1);
}

/**
 * Make HTTP request to the API
 */
function httpRequest(string $method, string $url, array $data = [], array $options = []): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $headers = ['Accept: application/json'];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers[] = 'Content-Type: application/json';
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    return [
        'code' => $httpCode,
        'body' => json_decode($response, true) ?? ['raw' => $response],
        'raw' => $response,
        'error' => $error,
    ];
}

/**
 * Visit URL using curl command with --resolve to bypass DNS
 */
function visitUrlWithResolve(string $host, string $tld): array
{
    $url = "https://{$host}.{$tld}/";
    // Use --resolve to map the hostname to 127.0.0.1
    $cmd = "curl -s -o /dev/null -w '%{http_code}' --resolve '{$host}.{$tld}:443:127.0.0.1' -k '{$url}' 2>&1";
    $httpCode = trim(shell_exec($cmd));

    return [
        'code' => (int) $httpCode,
        'url' => $url,
    ];
}

/**
 * Read status updates from CLI log files.
 */
function getLoggedStatuses(string $slug, string $type): array
{
    $home = $_SERVER['HOME'] ?? '/home/orbit';
    $logDir = $type === 'provision' ? 'provision' : 'deletion';
    $logFile = "{$home}/.config/orbit/logs/{$logDir}/{$slug}.log";

    if (! file_exists($logFile)) {
        return [];
    }

    $content = file_get_contents($logFile);
    preg_match_all('/Status: (\w+)/', $content, $matches);

    return $matches[1] ?? [];
}

/**
 * Check if required database tables exist
 */
function checkDatabaseTables(string $slug): array
{
    $requiredTables = ['users', 'sessions', 'migrations', 'jobs', 'password_reset_tokens'];
    $missing = [];

    $output = shell_exec("docker exec orbit-postgres psql -U orbit -d {$slug} -tAc \"SELECT tablename FROM pg_tables WHERE schemaname = 'public'\" 2>&1");

    if (strpos($output, 'does not exist') !== false) {
        return ['error' => 'Database does not exist'];
    }

    $tables = array_filter(array_map('trim', explode("\n", $output)));

    foreach ($requiredTables as $table) {
        if (! in_array($table, $tables)) {
            $missing[] = $table;
        }
    }

    return ['tables' => $tables, 'missing' => $missing];
}

/**
 * Check Laravel logs for errors
 */
function checkLaravelLogs(string $slug): array
{
    $home = $_SERVER['HOME'] ?? '/home/orbit';
    $logFile = "{$home}/projects/{$slug}/storage/logs/laravel.log";
    $errors = [];

    if (! file_exists($logFile)) {
        return ['exists' => false, 'errors' => []];
    }

    $content = file_get_contents($logFile);

    // Look for ERROR or CRITICAL level logs
    preg_match_all('/\[\d{4}-\d{2}-\d{2}[^\]]+\] \w+\.(ERROR|CRITICAL): (.+?)(?=\[\d{4}-\d{2}-\d{2}|\z)/s', $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $errors[] = [
            'level' => $match[1],
            'message' => trim(substr($match[2], 0, 200)),
        ];
    }

    return ['exists' => true, 'errors' => $errors, 'size' => strlen($content)];
}

// Step 2: Create project
echo "[2/9] Creating project via API...\n";
$createResponse = httpRequest('POST', "{$apiBase}/projects", [
    'name' => $slug,
    'template' => 'hardimpactdev/liftoff-starterkit',
    'visibility' => 'private',
    'php_version' => '8.5',
    'db_driver' => 'pgsql',
    'session_driver' => 'database',
    'cache_driver' => 'redis',
    'queue_driver' => 'redis',
]);

if ($createResponse['code'] !== 202) {
    echo "  FAIL: HTTP {$createResponse['code']}\n";
    echo '  Response: '.json_encode($createResponse['body'])."\n";
    exit(1);
}
echo "  OK - HTTP 202 Accepted\n\n";

// Step 3: Wait for provisioning
echo "[3/9] Waiting for provisioning (~30-60 seconds)...\n";
$timeout = 120;
$start = time();
$lastStatus = null;
$seen = [];

while (time() - $start < $timeout) {
    foreach (getLoggedStatuses($slug, 'provision') as $status) {
        if (! in_array($status, $seen)) {
            $seen[] = $status;
            echo "  -> {$status}\n";
            $provisionEvents[] = $status;
        }
        $lastStatus = $status;
    }
    if (in_array($lastStatus, ['ready', 'failed'])) {
        break;
    }
    usleep(500000);
}

if ($lastStatus === 'ready') {
    echo "  OK - Project provisioned successfully\n\n";
} else {
    echo "  FAIL: Provisioning did not complete (last: {$lastStatus})\n\n";
    $errors[] = "Provisioning stuck on: {$lastStatus}";
}

// Step 4: Validate database tables
echo "[4/9] Validating database migrations...\n";
$dbCheck = checkDatabaseTables($slug);

if (isset($dbCheck['error'])) {
    echo "  FAIL: {$dbCheck['error']}\n\n";
    $errors[] = "Database: {$dbCheck['error']}";
} elseif (! empty($dbCheck['missing'])) {
    echo '  FAIL: Missing tables: '.implode(', ', $dbCheck['missing'])."\n\n";
    $errors[] = 'Missing tables: '.implode(', ', $dbCheck['missing']);
} else {
    echo '  OK - All required tables exist ('.count($dbCheck['tables'])." tables)\n";
    echo '  Tables: '.implode(', ', $dbCheck['tables'])."\n\n";
}

// Step 5: Visit the project URL (using --resolve to bypass DNS)
echo "[5/9] Visiting project URL...\n";
$urlResponse = visitUrlWithResolve($slug, $tld);

if ($urlResponse['code'] === 200) {
    echo "  OK - HTTP 200 from {$urlResponse['url']}\n\n";
} elseif ($urlResponse['code'] === 500) {
    echo "  FAIL: HTTP 500 (server error) from {$urlResponse['url']}\n\n";
    $errors[] = 'Project URL returned HTTP 500';
} elseif ($urlResponse['code'] === 0) {
    echo "  FAIL: Could not connect to {$urlResponse['url']}\n\n";
    $errors[] = 'Could not connect to project URL';
} elseif ($urlResponse['code'] === 404) {
    echo "  FAIL: HTTP 404 - Site not found in Caddy\n\n";
    $errors[] = 'Project URL returned HTTP 404';
} else {
    echo "  WARN: HTTP {$urlResponse['code']} from {$urlResponse['url']}\n\n";
}

// Step 6: Check Laravel logs for errors
echo "[6/9] Checking Laravel logs for errors...\n";
$logCheck = checkLaravelLogs($slug);

if (! $logCheck['exists']) {
    echo "  OK - No log file yet (clean install)\n\n";
} elseif (empty($logCheck['errors'])) {
    echo "  OK - No errors in Laravel log ({$logCheck['size']} bytes)\n\n";
} else {
    $errorCount = count($logCheck['errors']);
    echo "  FAIL: Found {$errorCount} error(s) in Laravel log:\n";
    foreach (array_slice($logCheck['errors'], 0, 3) as $err) {
        echo "    [{$err['level']}] {$err['message']}\n";
    }
    if ($errorCount > 3) {
        echo '    ... and '.($errorCount - 3)." more\n";
    }
    echo "\n";
    $errors[] = "Laravel log contains {$errorCount} error(s)";
}

// Step 7-8: Delete project (unless --keep)
if (! $keepProject) {
    echo "[7/9] Deleting project via API...\n";
    $deleteResponse = httpRequest('DELETE', "{$apiBase}/projects/{$slug}");

    if ($deleteResponse['code'] !== 202) {
        echo "  FAIL: HTTP {$deleteResponse['code']}\n";
        $errors[] = 'Delete returned wrong HTTP code';
    } else {
        echo "  OK - HTTP 202 Accepted\n\n";
    }

    echo "[8/9] Waiting for deletion (~5-10 seconds)...\n";
    $timeout = 60;
    $start = time();
    $lastStatus = null;
    $seen = [];

    while (time() - $start < $timeout) {
        foreach (getLoggedStatuses($slug, 'deletion') as $status) {
            if (! in_array($status, $seen)) {
                $seen[] = $status;
                echo "  -> {$status}\n";
                $deletionEvents[] = $status;
            }
            $lastStatus = $status;
        }
        if (in_array($lastStatus, ['deleted', 'delete_failed'])) {
            break;
        }
        usleep(500000);
    }

    if ($lastStatus === 'deleted') {
        echo "  OK - Project deleted successfully\n\n";
    } else {
        echo "  FAIL: Deletion did not complete (last: {$lastStatus})\n\n";
        $errors[] = "Deletion stuck on: {$lastStatus}";
    }
} else {
    echo "[7/9] Skipped (--keep flag)\n";
    echo "[8/9] Skipped (--keep flag)\n\n";
}

// Step 9: Summary
echo "[9/9] Results Summary\n";
echo "=====================================\n";
echo 'Provision: '.implode(' -> ', $provisionEvents)."\n";
echo 'Database:  '.(empty($dbCheck['missing']) && ! isset($dbCheck['error']) ? 'OK' : 'FAILED')."\n";
echo "URL Test:  HTTP {$urlResponse['code']}\n";
echo 'Log Check: '.(empty($logCheck['errors']) ? 'OK' : count($logCheck['errors']).' errors')."\n";
if (! $keepProject) {
    echo 'Deletion:  '.implode(' -> ', $deletionEvents)."\n";
}
echo "=====================================\n\n";

if (empty($errors)) {
    echo "ALL TESTS PASSED\n";
    exit(0);
} else {
    echo "TESTS FAILED:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}
