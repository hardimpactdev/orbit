<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\ReservesRolePackages;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;
use Throwable;

final class FakeToolManager implements ReservesRolePackages, ToolManager
{
    /** @var list<string> */
    public array $roleOwned = [];

    /** @var list<string|null|Throwable> */
    public array $installedVersions = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, list<Throwable>> */
    public array $failures = [];

    public bool $supports = true;

    public bool $requiresAppRole = false;

    public bool $validPackage = true;

    public InstalledToolInventory $inventory;

    public function __construct(
        public ToolManagerName $managerName = ToolManagerName::Apt,
    ) {
        $this->inventory = new InstalledToolInventory([]);
    }

    public function roleOwns(string $package): bool
    {
        return in_array($package, $this->roleOwned, true);
    }

    public function name(): ToolManagerName
    {
        return $this->managerName;
    }

    public function supportsNode(Node $node): bool
    {
        if ($this->requiresAppRole) {
            return $node
                ->loadMissing('roles')
                ->roles->contains(
                    static fn ($role): bool => in_array($role->role->value, ['app-dev', 'app-prod'], strict: true),
                );
        }

        return $this->supports;
    }

    public function validatePackage(string $package): bool
    {
        $this->calls[] = 'validatePackage';

        return $this->validPackage;
    }

    public function managerVersion(Node $node): string
    {
        $this->calls[] = 'managerVersion';

        return '1.0.0';
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        $this->failOrCall('installedInventory');

        return $this->inventory;
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        return $this->next($this->installedVersions, 'installedVersion');
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return preg_match('/\A\d+(?:\.\d+){0,2}\z/', $rawVersion) === 1 ? $rawVersion : null;
    }

    /** @param list<string|null|Throwable> $queue */
    private function next(array &$queue, string $call): ?string
    {
        $this->calls[] = $call;
        $value = array_shift($queue);

        if ($value instanceof Throwable) {
            throw $value;
        }

        return $value;
    }

    private function failOrCall(string $call): void
    {
        $this->calls[] = $call;
        $failure = $this->failures[$call][0] ?? null;

        if (! $failure instanceof Throwable) {
            return;
        }

        array_shift($this->failures[$call]);

        throw $failure;
    }
}
