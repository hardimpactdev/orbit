<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\TemplateAnalyzer;

use HardImpact\Orbit\Core\Services\TemplateAnalyzer\Contracts\TemplateAnalyzerInterface;
use Illuminate\Support\Facades\Http;

/**
 * Service for analyzing GitHub templates and detecting their configuration.
 *
 * Supports multiple project types through pluggable analyzers.
 */
final class TemplateAnalyzerService
{
    /**
     * @var array<TemplateAnalyzerInterface>
     */
    private array $analyzers = [];

    /**
     * Register an analyzer.
     */
    public function registerAnalyzer(TemplateAnalyzerInterface $analyzer): self
    {
        $this->analyzers[] = $analyzer;

        return $this;
    }

    /**
     * Analyze a GitHub template repository.
     *
     * @param  string  $template  Repository in owner/repo format or full GitHub URL
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function analyze(string $template): array
    {
        // Extract owner/repo from various formats
        $repo = $this->extractRepo($template);
        if ($repo === null) {
            return [
                'success' => false,
                'error' => 'Invalid template format. Use owner/repo or a GitHub URL.',
            ];
        }

        // Detect the branch (try main first, then master)
        $branch = $this->detectDefaultBranch($repo);
        if ($branch === null) {
            return [
                'success' => false,
                'error' => 'Could not access repository. Check if it exists and is public.',
            ];
        }

        // Get repository root contents to detect project type
        $contents = $this->getRepoContents($repo, $branch);
        if ($contents === null) {
            return [
                'success' => false,
                'error' => 'Could not read repository contents.',
            ];
        }

        // Find a matching analyzer
        $analyzer = $this->findAnalyzer($contents);
        if (! $analyzer instanceof \App\Services\TemplateAnalyzer\Contracts\TemplateAnalyzerInterface) {
            return [
                'success' => false,
                'error' => 'Could not detect project type. Only Laravel templates are currently supported.',
            ];
        }

        // Run the analysis
        $result = $analyzer->analyze($repo, $branch);

        return [
            'success' => true,
            'data' => $result,
        ];
    }

    /**
     * Extract owner/repo from template string.
     */
    public function extractRepo(string $template): ?string
    {
        // Handle full GitHub URLs
        if (str_contains($template, 'github.com')) {
            $path = parse_url($template, PHP_URL_PATH);
            $parts = array_values(array_filter(explode('/', trim($path ?? '', '/'))));

            if (count($parts) >= 2) {
                $repo = $parts[1];
                // Remove .git suffix if present
                if (str_ends_with($repo, '.git')) {
                    $repo = substr($repo, 0, -4);
                }

                return "{$parts[0]}/{$repo}";
            }

            return null;
        }

        // Handle owner/repo format directly
        if (preg_match('/^[\w.-]+\/[\w.-]+$/', $template)) {
            return $template;
        }

        return null;
    }

    /**
     * Detect the default branch of a repository.
     */
    private function detectDefaultBranch(string $repo): ?string
    {
        // Try main first (GitHub default since 2020)
        foreach (['main', 'master'] as $branch) {
            $url = "https://raw.githubusercontent.com/{$repo}/{$branch}/README.md";
            $response = Http::timeout(5)->head($url);

            if ($response->successful() || $response->status() === 200) {
                return $branch;
            }

            // Also try fetching a common file to confirm branch exists
            $url = "https://api.github.com/repos/{$repo}/branches/{$branch}";
            $response = Http::timeout(5)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json'])
                ->get($url);

            if ($response->successful()) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * Get the list of files in the repository root.
     *
     * @return array<string>|null
     */
    private function getRepoContents(string $repo, string $branch): ?array
    {
        $url = "https://api.github.com/repos/{$repo}/contents?ref={$branch}";

        $response = Http::timeout(10)
            ->withHeaders(['Accept' => 'application/vnd.github.v3+json'])
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        $items = $response->json();
        if (! is_array($items)) {
            return null;
        }

        return array_map(fn (array $item) => $item['name'] ?? '', $items);
    }

    /**
     * Find an analyzer that supports this project type.
     */
    private function findAnalyzer(array $contents): ?TemplateAnalyzerInterface
    {
        foreach ($this->analyzers as $analyzer) {
            if ($analyzer->supports($contents)) {
                return $analyzer;
            }
        }

        return null;
    }
}
