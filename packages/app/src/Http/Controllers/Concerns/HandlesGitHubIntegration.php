<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers\Concerns;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\SshService;

trait HandlesGitHubIntegration
{
    /**
     * Get repository info from GitHub via gh CLI over SSH.
     */
    protected function getRepoInfoViaGh(Environment $environment, string $repo): ?array
    {
        $command = "gh api repos/{$repo} --jq '{is_template, default_branch, name, full_name, private, clone_url}' 2>/dev/null";
        $result = $this->getSshService()->execute($environment, $command);

        if (! $result['success'] || empty($result['output'])) {
            return null;
        }

        $data = json_decode((string) $result['output'], true);

        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * Fetch a file from GitHub via gh CLI over SSH.
     */
    protected function fetchFileViaGh(Environment $environment, string $repo, string $path): ?string
    {
        $command = "gh api repos/{$repo}/contents/{$path} --jq .content 2>/dev/null | base64 -d 2>/dev/null";
        $result = $this->getSshService()->execute($environment, $command);

        if (! $result['success'] || empty($result['output'])) {
            return null;
        }

        return $result['output'];
    }

    /**
     * Extract owner/repo from template string.
     */
    protected function extractRepoFromTemplate(string $template): ?string
    {
        if (str_contains($template, 'github.com')) {
            $path = parse_url($template, PHP_URL_PATH);
            $parts = array_values(array_filter(explode('/', trim($path ?? '', '/'))));

            if (count($parts) >= 2) {
                $repo = $parts[1];
                if (str_ends_with($repo, '.git')) {
                    $repo = substr($repo, 0, -4);
                }

                return "{$parts[0]}/{$repo}";
            }

            return null;
        }

        if (preg_match('/^[\w.-]+\/[\w.-]+$/', $template)) {
            return $template;
        }

        return null;
    }

    /**
     * Extract a display name from a template URL or repo string.
     */
    protected function extractTemplateName(string $template): string
    {
        // Handle full URLs
        if (str_contains($template, 'github.com')) {
            $path = parse_url($template, PHP_URL_PATH);
            $parts = explode('/', trim($path ?? '', '/'));

            return end($parts) ?: $template;
        }

        // Handle owner/repo format
        if (str_contains($template, '/')) {
            $parts = explode('/', $template);

            return end($parts);
        }

        return $template;
    }

    /**
     * Normalize a driver value (lowercase, trim).
     */
    protected function normalizeDriver(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strtolower(trim($value));
    }

    /**
     * Extract minimum PHP version from composer.json constraint (for display).
     */
    protected function extractMinPhpVersion(string $constraint): ?string
    {
        $constraint = trim($constraint);

        if (preg_match('/(\d+\.\d+)(?:\.\d+)?/', $constraint, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get the recommended (highest compatible) PHP version for a constraint.
     * Always prefers the latest PHP version unless explicitly excluded.
     *
     * Examples:
     * - ^8.3 → 8.5 (allows 8.3+)
     * - ^8.4 → 8.5 (allows 8.4+)
     * - ~8.3.0 → 8.3 (only allows 8.3.x)
     * - 8.4.* → 8.4 (only allows 8.4.x)
     * - <8.5 → 8.4 (excludes 8.5)
     * - >=8.3 <8.5 → 8.4 (range excludes 8.5)
     */
    protected function getRecommendedPhpVersion(string $constraint): string
    {
        $constraint = trim($constraint);
        $availableVersions = ['8.5', '8.4', '8.3'];

        // Check for explicit upper bound that excludes versions
        // Patterns like: <8.5, <=8.4, <8.5.0
        if (preg_match('/<\s*(\d+)\.(\d+)/', $constraint, $matches)) {
            $maxMajor = (int) $matches[1];
            $maxMinor = (int) $matches[2];

            foreach ($availableVersions as $version) {
                [$major, $minor] = explode('.', $version);
                // Version must be strictly less than the upper bound
                if ((int) $major < $maxMajor || ((int) $major === $maxMajor && (int) $minor < $maxMinor)) {
                    return $version;
                }
            }
        }

        // Check for tilde constraint ~8.x.y which locks to 8.x.*
        // ~8.3.0 means >=8.3.0 <8.4.0
        if (preg_match('/~\s*(\d+)\.(\d+)\./', $constraint, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        // Check for wildcard constraint 8.x.* which locks to 8.x
        if (preg_match('/(\d+)\.(\d+)\.\*/', $constraint, $matches)) {
            return $matches[1].'.'.$matches[2];
        }

        // For caret (^), greater-than (>=, >), or simple version constraints,
        // the latest version is compatible
        // ^8.3 means >=8.3.0 <9.0.0, so 8.5 is fine
        // >=8.3 means 8.3 or higher, so 8.5 is fine
        return '8.5';
    }

    /**
     * Get the SSH service instance.
     * Classes using this trait must implement this method.
     */
    abstract protected function getSshService(): SshService;
}
