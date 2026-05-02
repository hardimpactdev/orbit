<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules;

use OrbitDocsLinter\CommandDocsLintContext;
use OrbitDocsLinter\CommandDocsLintFinding;
use OrbitDocsLinter\CommandDocsLintRule;

final class MarkdownLinkIntegrityRule implements CommandDocsLintRule
{
    public function id(): string
    {
        return 'command_docs.markdown_link_integrity';
    }

    public function group(): string
    {
        return 'references';
    }

    public function check(CommandDocsLintContext $context): array
    {
        $findings = [];

        foreach ($context->convertedFamilyDirectories() as $familyDirectory) {
            foreach ($context->markdownFiles($familyDirectory) as $file) {
                foreach ($this->relativeLinks($context->read($file)) as $link) {
                    $target = $this->linkTarget($link);

                    if ($target === null) {
                        continue;
                    }

                    $resolvedTarget = $this->normalizePath(dirname($file).'/'.$target);

                    if (! file_exists($resolvedTarget)) {
                        $findings[] = new CommandDocsLintFinding(
                            path: $context->relativePath($file),
                            ruleId: $this->id(),
                            message: "Markdown link target does not exist: {$link}.",
                        );
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function relativeLinks(string $contents): array
    {
        $contents = $this->stripCode($contents);

        preg_match_all('/(?<!!)\[[^\]]+\]\((?<target>[^)]+)\)/', $contents, $matches);

        return $matches['target'];
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

        if ($targetWithoutAnchor === '') {
            return null;
        }

        if (str_starts_with($targetWithoutAnchor, '/')) {
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

        return '/'.implode('/', $segments);
    }
}
