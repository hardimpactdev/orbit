<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\TemplateAnalyzer\Contracts;

interface TemplateAnalyzerInterface
{
    /**
     * Check if this analyzer can handle the given template.
     *
     * @param  array<string, mixed>  $repoContents  List of files/directories in the repo root
     */
    public function supports(array $repoContents): bool;

    /**
     * Get the project type identifier.
     */
    public function getType(): string;

    /**
     * Analyze the template and extract configuration defaults.
     *
     * @param  string  $repo  The repository in owner/repo format
     * @param  string  $branch  The branch to analyze
     * @return array<string, mixed> The extracted configuration
     */
    public function analyze(string $repo, string $branch): array;
}
