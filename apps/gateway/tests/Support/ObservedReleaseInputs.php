<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Fleet\ReleaseNodeInputs;
use App\Models\Node;
use ArrayObject;
use Closure;
use Illuminate\Support\Facades\DB;

/** Release inputs that record the SQLite transaction level of every read, and can run a step after reading a Node's roles. */
final readonly class ObservedReleaseInputs extends ReleaseNodeInputs
{
    /** @param ArrayObject<int, array{string, int}> $reads */
    public function __construct(private ArrayObject $reads, private ?Closure $afterRoles = null) {}

    public function host(?string $root = null, ?Node $node = null): string
    {
        $this->reads->append(['host', DB::transactionLevel()]);

        return parent::host($root, $node);
    }

    public function roles(Node $node, bool $release = true, ?array $selected = null, ?string $root = null, array $renderedRoles = []): array
    {
        $this->reads->append(['roles', DB::transactionLevel()]);
        $roles = parent::roles($node, $release, $selected, $root, $renderedRoles);
        if ($this->afterRoles !== null) {
            ($this->afterRoles)($node);
        }

        return $roles;
    }

    public function extractHostTree(string $repository, string $commit, string $folder): void
    {
        $this->reads->append(['extractHostTree', DB::transactionLevel()]);
        parent::extractHostTree($repository, $commit, $folder);
    }
}
