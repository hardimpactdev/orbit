<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolManagerException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/**
 * Resolves the enrolled account's existing Homebrew prefix and builds a fixed brew argv.
 * Formula and cask commands share this probe. It does not install, fetch, check out, or repin Homebrew.
 */
final readonly class HomebrewMacCommand
{
    /** @var array<int, string> */
    private const array MACOS_BOTTLE_SYMBOLS = [
        27 => 'golden_gate',
        26 => 'tahoe',
        15 => 'sequoia',
        14 => 'sonoma',
        13 => 'ventura',
        12 => 'monterey',
        11 => 'big_sur',
    ];

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private AgentCommandRunner $agents,
    ) {}

    public function resolvePrefix(Node $node): string
    {
        return $this->resolveScope($node)->prefix;
    }

    public function resolveScope(Node $node): HomebrewMacScope
    {
        try {
            $scope = $this->agents->run($node, 'tools.inspect-homebrew-scope');
        } catch (AgentCommandRefused|AgentRunnerFailure $error) {
            $step = match ($error instanceof AgentCommandRefused ? $error->errorCode : null) {
                'tools.homebrew_scope_absent' => 'manager-absent',
                'tools.homebrew_scope_conflict' => 'manager-conflict',
                default => 'manager-probe',
            };

            throw new ToolManagerException($step, 'The Homebrew scope is unavailable.', previous: $error);
        }

        if (! is_string($scope['prefix'] ?? null) || ! is_string($scope['home'] ?? null)) {
            throw new ToolManagerException('manager-probe', 'The agent returned an invalid Homebrew scope.');
        }

        return new HomebrewMacScope($scope['prefix'], $scope['home']);
    }

    /**
     * The bottle tag formulae and casks share. arm64 prefixes the symbol; Intel uses the symbol alone.
     */
    public function bottleTag(Node $node): string
    {
        if ($node->architecture !== 'arm64' && $node->architecture !== 'x86_64') {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The node architecture has no supported Homebrew bottle.',
            );
        }

        $result = $this->commands->execute($node, ['/usr/bin/sw_vers', '-productVersion']);
        $this->guardSuccessfulResult(
            result: $result,
            step: 'candidate-version',
            message: 'The macOS product version probe failed.',
        );
        $lines = preg_split('/\R/', $result->stdout, limit: 2);
        $version = is_array($lines) ? ($lines[0] ?? '') : '';

        if (
            $version === ''
            || strlen($version) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $version) === 1
            || preg_match('/\A(\d+)(?:\.\d+){0,2}\z/D', $version, $matches) !== 1
        ) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The macOS product version probe returned malformed output.',
                result: $result,
            );
        }

        $symbol = self::MACOS_BOTTLE_SYMBOLS[(int) $matches[1]] ?? null;

        if (! is_string($symbol)) {
            throw new ToolManagerException(
                step: 'candidate-version',
                message: 'The macOS product version has no compatible Homebrew bottle.',
                result: $result,
            );
        }

        return $node->architecture === 'arm64' ? 'arm64_'.$symbol : $symbol;
    }

    /**
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    public function brew(Node $node, bool $refreshApi, array $arguments): array
    {
        return $this->command($this->resolvePrefix($node), $refreshApi, $arguments);
    }

    /**
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    public function command(string $prefix, bool $refreshApi, array $arguments): array
    {
        $command = [
            'env',
            'HOMEBREW_NO_AUTO_UPDATE=1',
            'HOMEBREW_NO_ANALYTICS=1',
            'HOMEBREW_NO_ENV_HINTS=1',
            'HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1',
            'HOMEBREW_NO_INSTALL_CLEANUP=1',
        ];

        if ($refreshApi) {
            $command[] = 'HOMEBREW_FORCE_API_AUTO_UPDATE=1';
        }

        $command[] = 'PATH='.$prefix.'/bin:/usr/bin:/bin';
        $command[] = $prefix.'/bin/brew';

        return [...$command, ...$arguments];
    }

    private function guardSuccessfulResult(CommandResult $result, string $step, string $message): void
    {
        if ($result->succeeded()) {
            return;
        }

        throw new ToolManagerException(step: $step, message: $message, result: $result);
    }
}
