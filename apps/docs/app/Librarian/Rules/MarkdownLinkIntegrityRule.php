<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class MarkdownLinkIntegrityRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'references';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->markdownFiles($familyDirectory) as $file) {
                array_push($findings, ...$this->checkFile($file));
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFile(string $file): array
    {
        $findings = [];
        $contents = $this->docs->contents($file);

        foreach ($this->relativeLinks($contents) as $link) {
            $target = $this->linkTarget($link);

            if ($target === null) {
                continue;
            }

            $resolvedTarget = $this->normalizePath(dirname($file).'/'.$target);

            if ($this->docs->exists($resolvedTarget)) {
                continue;
            }

            $findings[] = new Finding(
                path: $this->docs->relativePath($file),
                line: null,
                severity: FindingSeverity::Error,
                rule: 'command_docs.markdown_link_integrity',
                message: "Markdown link target does not exist: {$link}.",
            );
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function relativeLinks(string $contents): array
    {
        preg_match_all('/(?<!!)\[[^\]]+\]\((?<target>[^)]+)\)/', $this->stripCode($contents), $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_column($matches, 'target'), is_string(...)));
    }

    private function stripCode(string $contents): string
    {
        return preg_replace('/```.*?```/s', '', $contents) ?? $contents;
    }

    private function linkTarget(string $link): ?string
    {
        $target = trim($link);
        $target = trim($target, '<>');
        $target = preg_replace('/\s+".*"$/', '', $target) ?? $target;

        if ($target === '' || str_starts_with($target, '#') || str_starts_with($target, '?')) {
            return null;
        }

        if (str_contains($target, '://') || str_starts_with($target, 'mailto:')) {
            return null;
        }

        $targetWithoutAnchor = explode('#', $target, 2)[0];

        if ($targetWithoutAnchor === '' || str_starts_with($targetWithoutAnchor, '/')) {
            return null;
        }

        return $targetWithoutAnchor;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', array_values(array_filter($segments, is_scalar(...))));
    }
}
