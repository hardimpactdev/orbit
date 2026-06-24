<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\CliCommand;
use App\Librarian\CliSurface;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

/**
 * The canonical `## Signature` block of every command doc must match the live
 * command definition: same command name, same argument order, and the same
 * option set. Required-versus-optional styling is not compared because docs
 * may document prompt-backed arguments as required.
 */
final readonly class SignatureLiveSurfaceRule implements GroupedRule
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
        $liveCommands = [];

        foreach ($this->surface->publicCommands() as $command) {
            $liveCommands[$command->slug()] = $command;
        }

        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            foreach ($this->docs->commandDirectories($familyDirectory) as $commandDirectory) {
                array_push($findings, ...$this->checkCommandDirectory($commandDirectory, $liveCommands));
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, CliCommand>  $liveCommands
     * @return list<Finding>
     */
    private function checkCommandDirectory(string $commandDirectory, array $liveCommands): array
    {
        $slug = $this->docs->commandName($commandDirectory);
        $canonicalFile = "{$commandDirectory}/technical/1_{$slug}.md";
        $live = $liveCommands[$slug] ?? null;

        if ($live === null || ! is_file($canonicalFile)) {
            return [];
        }

        $contents = file_get_contents($canonicalFile) ?: '';
        $signature = $this->parseSignature($contents);

        if ($signature === null) {
            return [];
        }

        $findings = [];
        $line = $this->signatureLine($contents);

        if ($signature['name'] !== $live->name) {
            $findings[] = $this->finding(
                $canonicalFile,
                "Signature names `orbit {$signature['name']}` but the live command is `orbit {$live->name}`.",
                $line,
            );
        }

        if ($signature['arguments'] !== $live->arguments) {
            $findings[] = $this->finding(
                $canonicalFile,
                'Signature arguments do not match the live command definition. '
                ."Docs have: [{$this->tokenList(
                    $signature['arguments'],
                )}]. Live command has: [{$this->tokenList($live->arguments)}] in that order.",
                $line,
            );
        }

        $docsOptions = $signature['options'];
        $liveOptions = $this->publicOptionsFor($live);
        sort($docsOptions);
        sort($liveOptions);

        if ($docsOptions !== $liveOptions) {
            $missingFromDocs = array_values(array_diff($liveOptions, $docsOptions));
            $missingFromLive = array_values(array_diff($docsOptions, $liveOptions));
            $details = [];

            if ($missingFromDocs !== []) {
                $details[] = "live options missing from the signature: {$this->optionList($missingFromDocs)}";
            }

            if ($missingFromLive !== []) {
                $details[] = "documented options missing from the live command: {$this->optionList($missingFromLive)}";
            }

            $findings[] = $this->finding(
                $canonicalFile,
                'Signature options do not match the live command definition: '.implode('; ', $details).'.',
                $line,
            );
        }

        return $findings;
    }

    /**
     * @return array{name: string, arguments: list<string>, options: list<string>}|null
     */
    private function parseSignature(string $contents): ?array
    {
        if (preg_match('/\n## Signature\s*(?<section>.*?)(?:\n## |\z)/s', $contents, $matches) !== 1) {
            return null;
        }

        if (preg_match('/```[a-z]*\n(?<body>.*?)```/s', $matches['section'], $block) !== 1) {
            return null;
        }

        $line = trim((string) preg_replace('/\s+/', ' ', $block['body']));

        if (! str_starts_with($line, 'orbit ')) {
            return null;
        }

        $tokens = array_values(array_filter(explode(' ', substr($line, strlen('orbit ')))));
        $nameTokens = [];

        foreach ($tokens as $token) {
            if (preg_match('/^[a-z0-9][a-z0-9:_-]*$/', $token) !== 1) {
                break;
            }

            $nameTokens[] = $token;
        }

        if ($nameTokens === []) {
            return null;
        }

        $arguments = [];
        $options = [];

        foreach (array_slice($tokens, count($nameTokens)) as $token) {
            if ($token === '[--]' || $token === '--') {
                continue;
            }

            if (str_starts_with($token, '--') || str_starts_with($token, '[--')) {
                preg_match_all('/--(?<option>[a-z0-9][a-z0-9-]*)/', $token, $optionMatches, PREG_SET_ORDER);
                array_push(
                    $options,
                    ...array_values(array_filter(array_column($optionMatches, 'option'), is_string(...))),
                );

                continue;
            }

            if (preg_match('/^\[?<?(?<argument>[a-z0-9][a-z0-9_-]*)(\.\.\.)?>?\]?$/', $token, $argumentMatch) === 1) {
                $arguments[] = $argumentMatch['argument'];
            }
        }

        return [
            'name' => implode(' ', $nameTokens),
            'arguments' => $arguments,
            'options' => array_values(array_unique($options)),
        ];
    }

    private function signatureLine(string $contents): ?int
    {
        if (preg_match('/^## Signature$/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_count(substr($contents, 0, (int) $match[0][1]), "\n") + 1;
    }

    /**
     * @return list<string>
     */
    private function publicOptionsFor(CliCommand $command): array
    {
        $optionAliases = match ($command->name) {
            'analytics:update' => ['requested-version' => 'version'],
            'process:add' => ['service-version' => 'version'],
            default => [],
        };

        if ($optionAliases === []) {
            return $command->options;
        }

        return array_values(array_map(
            static fn (string $option): string => $optionAliases[$option] ?? $option,
            $command->options,
        ));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function tokenList(array $tokens): string
    {
        return $tokens === [] ? '(none)' : implode(', ', $tokens);
    }

    /**
     * @param  list<string>  $options
     */
    private function optionList(array $options): string
    {
        return implode(', ', array_map(
            static fn (string $option): string => "`--{$option}`",
            $options,
        ));
    }

    private function finding(string $path, string $message, ?int $line): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.signature_live_surface',
            message: $message,
        );
    }
}
