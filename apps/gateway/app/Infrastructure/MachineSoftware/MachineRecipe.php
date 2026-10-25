<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use InvalidArgumentException;

final readonly class MachineRecipe
{
    /**
     * @param  list<string>  $gitPackages
     * @param  list<string>  $architectures
     * @param  array<string, ToolPin>  $tools
     * @param  array<string, list<string>>  $profiles  Effective, sorted tool sets, including common tools.
     * @param  array<string, PhpBuild>  $php  Keyed by PHP version, such as 8.5.
     * @param  array<string, string>  $agentDigests  Resolved Linux delivery pins; empty only for source intent.
     */
    public function __construct(
        public string $identity,
        public string $snapshot,
        public array $architectures,
        public array $tools,
        public array $profiles,
        public string $canonicalJson,
        public string $json = '{}',
        public array $php = [],
        public string $ciPhp = '',
        public PhpPolicy $phpPolicy = new PhpPolicy,
        public string $artifactMirror = '',
        public array $gitPackages = [],
        public array $agentDigests = [],
    ) {}

    /** The recipe architecture of a machine architecture, or null when the recipe has none for it. */
    public static function architecture(?string $architecture): ?string
    {
        return match ($architecture) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };
    }

    public function agentDigest(string $architecture): string
    {
        $platform = match ($architecture) {
            'amd64', 'x86_64' => 'linux-x86_64',
            'arm64', 'aarch64' => 'linux-aarch64',
            default => throw new InvalidArgumentException("The recipe has no agent platform for [{$architecture}]."),
        };

        return $this->agentDigests[$platform] ?? throw new InvalidArgumentException("The recipe has no resolved agent digest for [{$platform}].");
    }

    public function php(string $version): PhpBuild
    {
        return $this->php[$version] ?? throw new InvalidArgumentException("The recipe has no PHP build for version [{$version}].");
    }

    /**
     * The CLI extension list of each PHP set that `$tools` holds whole, for the remote programs to check.
     *
     * @param  list<string>  $tools
     * @return array<string, list<string>>
     */
    public function phpExtensions(array $tools): array
    {
        $extensions = [];
        foreach ($this->php as $version => $build) {
            if (array_diff($build->packagesFor(false), $tools) === []) {
                $extensions[$version] = $build->cliExtensions();
            }
        }

        return $extensions;
    }

    /** @return list<string> */
    public function profile(string $role, ?string $architecture = null): array
    {
        $tools = $this->profiles[$role] ?? throw new InvalidArgumentException("Unknown role/profile [{$role}]. Expected a declared Node role, operator, or base.");
        if ($architecture !== null) {
            if (! in_array($architecture, $this->architectures, true)) {
                throw new InvalidArgumentException("Unknown architecture [{$architecture}].");
            }
            foreach ($tools as $name) {
                $pin = $this->tools[$name];
                if ($pin->source !== 'ubuntu' && ! isset($pin->artifacts[$architecture])) {
                    foreach ($this->php as $build) {
                        if (in_array($name, $build->packages, true)) {
                            throw new InvalidArgumentException("PHP is not available for {$architecture}");
                        }
                    }
                    throw new InvalidArgumentException("Profile [{$role}] requires tool [{$name}], unavailable for architecture [{$architecture}].");
                }
            }
        }

        return $tools;
    }

    /** @return list<string> */
    public function supportedArchitectures(string $role): array
    {
        return array_values(array_filter($this->architectures, function (string $architecture) use ($role): bool {
            foreach ($this->profile($role) as $name) {
                $tool = $this->tools[$name];
                if ($tool->source !== 'ubuntu' && ! isset($tool->artifacts[$architecture])) {
                    return false;
                }
            }

            return true;
        }));
    }
}
