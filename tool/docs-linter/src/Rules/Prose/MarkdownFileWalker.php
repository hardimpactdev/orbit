<?php

declare(strict_types=1);

namespace OrbitDocsLinter\Rules\Prose;

use OrbitDocsLinter\CommandDocsLintContext;

/**
 * Returns the markdown files a prose rule should inspect under the active scan root.
 *
 * Skips working/scratch directories that are not part of the product contract:
 * `superpowers/`, `porting/`, `working/`. Always includes top-level `docs/*.md`
 * files when they fall under the scan root (the existing `DocumentComplexityRule`
 * only walked converted command families, missing `ARCHITECTURE.md` and friends).
 */
final class MarkdownFileWalker
{
    private const SKIP_SEGMENTS = [
        '/superpowers/',
        '/porting/',
        '/working/',
        '/.worktrees/',
    ];

    /**
     * @return list<string>
     */
    public static function files(CommandDocsLintContext $context): array
    {
        $root = $context->repositoryRoot.'/docs';

        $candidates = $context->markdownFiles($root, recursive: true);
        $files = [];

        foreach ($candidates as $file) {
            if (self::shouldSkip($file)) {
                continue;
            }

            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    private static function shouldSkip(string $path): bool
    {
        foreach (self::SKIP_SEGMENTS as $segment) {
            if (str_contains($path, $segment)) {
                return true;
            }
        }

        return false;
    }
}
