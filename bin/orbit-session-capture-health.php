<?php

declare(strict_types=1);

/**
 * Returns problem string if the packet names lanes and active/archived
 * manifests contain zero healthy captures without a provider-naming waiver.
 */
function capture_health_problem_for_worktree(string $root, string $worktree, string $branch): ?string
{
    $loopPath = $worktree.'/.orbit/loop.md';

    if (! is_file($loopPath)) {
        return null;
    }

    $agentSessionsDirs = [$worktree.'/.orbit/agent-sessions'];

    foreach (matching_session_archive_dirs($root, $branch) as $archiveDir) {
        $agentSessionsDirs[] = $archiveDir.'/agent-sessions';
    }

    return capture_health_problem_for_sources($loopPath, $agentSessionsDirs);
}

function capture_health_problem_for_loop(string $loopPath, string $agentSessionsDir): ?string
{
    return capture_health_problem_for_sources($loopPath, [$agentSessionsDir]);
}

/**
 * @param list<string> $agentSessionsDirs
 */
function capture_health_problem_for_sources(string $loopPath, array $agentSessionsDirs): ?string
{
    $loopContent = (string) file_get_contents($loopPath);

    if (! packet_names_agent_lanes($loopContent)) {
        return null;
    }

    foreach ($agentSessionsDirs as $agentSessionsDir) {
        if (healthy_agent_session_count($agentSessionsDir) > 0) {
            return null;
        }
    }

    if (packet_has_agent_session_capture_waiver($loopContent)) {
        return null;
    }

    return 'zero healthy agent session captures for named lanes (worker/reviewer/analyzer) and no explicit waiver row; add captures or waiver label naming providers';
}

function packet_names_agent_lanes(string $loopContent): bool
{
    return preg_match('/^\s*-\s*(Worker|Reviewer|Analyzer):.*\bworker\s+[a-z][a-z0-9-]*\b/im', $loopContent) === 1;
}

function packet_has_agent_session_capture_waiver(string $loopContent): bool
{
    if (preg_match('/^-\s*Agent session capture waivers:\s*(.+)$/im', $loopContent, $match) !== 1) {
        return false;
    }

    $value = strtolower(trim($match[1]));

    if ($value === '' || $value === 'none') {
        return false;
    }

    return (
        preg_match('/\b(codex|claude|grok|antigravity|terminal|opencode|gemini|amp|copilot|kimi|unknown)\b/i', $value)
        === 1
    );
}

function healthy_agent_session_count(string $agentSessionsDir): int
{
    $healthy = 0;

    foreach (agent_session_manifest_paths($agentSessionsDir) as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            continue;
        }

        if (($manifest['status'] ?? null) === 'ok') {
            $healthy++;
        }

        if (isset($manifest['sessions']) && is_array($manifest['sessions'])) {
            foreach ($manifest['sessions'] as $session) {
                if (is_array($session) && ($session['status'] ?? null) === 'ok') {
                    $healthy++;
                }
            }
        }

        if (isset($manifest['providers']) && is_array($manifest['providers'])) {
            foreach ($manifest['providers'] as $provider) {
                if (is_array($provider) && (int) ($provider['ok'] ?? 0) > 0) {
                    $healthy += (int) $provider['ok'];
                }
            }
        }
    }

    return $healthy;
}

/**
 * @return list<string>
 */
function agent_session_manifest_paths(string $agentSessionsDir): array
{
    if (! is_dir($agentSessionsDir)) {
        return [];
    }

    $paths = [];
    $topLevelManifest = $agentSessionsDir.'/manifest.json';

    if (is_file($topLevelManifest)) {
        $paths[] = $topLevelManifest;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($agentSessionsDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $entry) {
        if (! $entry->isFile() || $entry->getFilename() !== 'manifest.json') {
            continue;
        }

        $path = $entry->getPathname();
        $manifestDirectory = dirname($path);
        $providerDirectory = dirname($manifestDirectory);
        $isDirectProviderTransactionBackup =
            dirname($providerDirectory) === rtrim($agentSessionsDir, DIRECTORY_SEPARATOR)
            && preg_match('/^\..+\.backup-.+$/', basename($manifestDirectory)) === 1;

        if ($path !== $topLevelManifest && ! $isDirectProviderTransactionBackup) {
            $paths[] = $path;
        }
    }

    sort($paths);

    return $paths;
}
