<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use InvalidArgumentException;

final readonly class MachineRecipe
{
    /**
     * @param  list<string>  $architectures
     * @param  array<string, ToolPin>  $tools
     * @param  array<string, list<string>>  $profiles  Effective, sorted tool sets, including common tools.
     * @param  array<string, PhpBuild>  $php  Keyed by PHP version, such as 8.5.
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
    ) {}

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
            if (array_diff($build->packages, $tools) === []) {
                $extensions[$version] = $build->extensions;
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
