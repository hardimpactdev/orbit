<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\CliSurface;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

/**
 * Every public Orbit CLI command must have a command doc directory, and every
 * command doc directory must map back to a live public command unless its
 * public page is explicitly marked removed or reserved.
 */
final readonly class CommandSurfaceCoverageRule implements GroupedRule
{
    private const array OFFLINE_MARKERS = [
        '<!-- command-status: removed -->',
        '<!-- command-status: reserved -->',
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
        private CliSurface $surface,
    ) {}

    public function group(): string
    {
        return 'references';
    }

    public function check(): array
    {
        $commandDirectories = $this->commandDirectoriesBySlug();
        $findings = [];
        $liveSlugs = [];

        foreach ($this->surface->publicCommands() as $command) {
            $slug = $command->slug();
            $liveSlugs[$slug] = true;

            if (isset($commandDirectories[$slug])) {
                continue;
            }

            $findings[] = $this->finding(
                $this->familyDirectoryFor($command->familyToken()) ?? $this->docs->commandsRoot(),
                "Public command `{$command->name}` has no command doc directory `{$slug}` under docs/domains. Add the command contract docs or hide the command.",
            );
        }

        foreach ($commandDirectories as $slug => $directory) {
            if (isset($liveSlugs[$slug]) || $this->isIntentionallyOffline($directory, $slug)) {
                continue;
            }

            $findings[] = $this->finding(
                $directory,
                "Command doc `{$slug}` has no matching public CLI command. Remove the doc directory or mark its public page with `<!-- command-status: removed -->` or `<!-- command-status: reserved -->`.",
            );
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function commandDirectoriesBySlug(): array
    {
        $directories = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                $directories[$this->docs->commandName($commandDirectory)] = $commandDirectory;
            }
        }

        ksort($directories);

        return $directories;
    }

    private function familyDirectoryFor(string $familyToken): ?string
    {
        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            $family = $this->docs->familyName($familyDirectory);

            if ($family === $familyToken || str_starts_with($familyToken, "{$family}-")) {
                return $familyDirectory;
            }
        }

        return null;
    }

    private function isIntentionallyOffline(string $directory, string $slug): bool
    {
        $publicPage = "{$directory}/{$slug}.md";

        if (! is_file($publicPage)) {
            return false;
        }

        $contents = file_get_contents($publicPage) ?: '';

        return array_any(
            self::OFFLINE_MARKERS,
            static fn (string $marker): bool => str_contains($contents, $marker),
        );
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.live_surface_coverage',
            message: $message,
        );
    }
}
