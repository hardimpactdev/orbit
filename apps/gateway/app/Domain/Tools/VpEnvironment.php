<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use InvalidArgumentException;

final readonly class VpEnvironment
{
    public function __construct(
        public string $store,
        public string $vpVersion,
        public string $nodeVersion,
        public string $packageManager,
        public string $packageManagerVersion,
        public ?string $packageManagerHash = null,
    ) {
        foreach ([$vpVersion, $nodeVersion, $packageManagerVersion] as $version) {
            if (preg_match('/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\z/D', $version) !== 1) {
                throw new InvalidArgumentException('Invalid vp environment version.');
            }
        }
        if ($packageManagerHash !== null && preg_match('/\Asha(?:224|256|384|512)\.[a-f0-9]+\z/Di', $packageManagerHash) !== 1) {
            throw new InvalidArgumentException('Invalid package manager integrity hash.');
        }
        if (! in_array($packageManager, ['npm', 'pnpm', 'yarn', 'bun'], true) || ! str_starts_with($store, '/') || str_contains($store, "\0")) {
            throw new InvalidArgumentException('Invalid vp environment.');
        }
    }

    public static function fromArray(mixed $value): self
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Missing vp environment.');
        }
        foreach (['store', 'vp_version', 'node_version', 'package_manager', 'package_manager_version'] as $key) {
            if (! isset($value[$key]) || ! is_string($value[$key])) {
                throw new InvalidArgumentException('Invalid vp environment record.');
            }
        }

        $hash = $value['package_manager_hash'] ?? null;
        if ($hash !== null && ! is_string($hash)) {
            throw new InvalidArgumentException('Invalid package manager integrity hash.');
        }

        return new self($value['store'], $value['vp_version'], $value['node_version'], $value['package_manager'], $value['package_manager_version'], $hash);
    }

    /** @return array<string, string|null> */
    public function record(string $status): array
    {
        return [
            'store' => $this->store,
            'vp_version' => $this->vpVersion,
            'node_version' => $this->nodeVersion,
            'package_manager' => $this->packageManager,
            'package_manager_version' => $this->packageManagerVersion,
            'package_manager_hash' => $this->packageManagerHash,
            'status' => $status,
        ];
    }

    /** @return array<string, string|null> */
    public function input(): array
    {
        return [
            'store' => $this->store,
            'node_version' => $this->nodeVersion,
            'package_manager' => $this->packageManager,
            'package_manager_version' => $this->packageManagerVersion,
            'package_manager_hash' => $this->packageManagerHash,
        ];
    }
}
