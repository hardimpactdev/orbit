<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\CliCommand;
use App\Librarian\CliSurface;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class PublicStreamJsonOptionContractRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
        private CliSurface $surface,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $directoriesBySlug = $this->commandDirectoriesBySlug();
        $findings = [];

        foreach ($this->surface->publicCommands() as $command) {
            if (! in_array('stream-json', $command->options, strict: true)) {
                continue;
            }

            $publicFile = $this->publicFileFor($command, $directoriesBySlug);

            if ($publicFile === null || ! is_file($publicFile)) {
                continue;
            }

            $contents = file_get_contents($publicFile);

            if ($contents !== false && str_contains($contents, '--stream-json')) {
                continue;
            }

            $findings[] = new Finding(
                path: $this->docs->relativePath($publicFile),
                line: null,
                severity: FindingSeverity::Error,
                rule: 'command_docs.public_stream_json_option_contract',
                message: 'Public command page must mention `--stream-json` when the live command accepts it.',
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

        return $directories;
    }

    /**
     * @param  array<string, string>  $directoriesBySlug
     */
    private function publicFileFor(CliCommand $command, array $directoriesBySlug): ?string
    {
        $directory = $directoriesBySlug[$command->slug()] ?? null;

        return $directory === null ? null : "{$directory}/{$command->slug()}.md";
    }
}
