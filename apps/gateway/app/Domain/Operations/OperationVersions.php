<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use LogicException;

final class OperationVersions
{
    /** @return array<string,array{current:int,retained:array<int,array<string,mixed>>,deprecated:list<int>}> */
    public static function policies(): array
    {
        $raw = config('operation_versions', []);
        if (! is_array($raw)) {
            throw new LogicException('Invalid Operation version registry.');
        }
        $policies = [];
        foreach ($raw as $kind => $policy) {
            if (! is_string($kind) || ! preg_match(OperationKinds::NamePattern, $kind) || ! is_array($policy)
                || array_diff(array_keys($policy), ['current', 'retained', 'deprecated']) !== []) {
                throw new LogicException('Invalid Operation version declaration.');
            }
            $current = $policy['current'] ?? 1;
            $retained = $policy['retained'] ?? [];
            $deprecated = $policy['deprecated'] ?? [];
            if (! is_int($current) || $current < 1 || ! is_array($retained) || isset($retained[$current])
                || ! is_array($deprecated) || ! array_is_list($deprecated)
            ) {
                throw new LogicException('Invalid Operation version numbers or handlers.');
            }
            $handlers = $closed = [];
            foreach ($retained as $version => $definition) {
                if (! is_int($version) || $version < 1 || ! is_array($definition)) {
                    throw new LogicException('Invalid retained Operation handler.');
                }
                $handlers[$version] = [];
                foreach ($definition as $field => $value) {
                    if (! is_string($field)) {
                        throw new LogicException('Invalid retained Operation handler field.');
                    }
                    $handlers[$version][$field] = $value;
                }
            }
            foreach ($deprecated as $version) {
                if (! is_int($version) || $version < 1) {
                    throw new LogicException('Invalid deprecated Operation version.');
                }
                $closed[] = $version;
            }
            $policies[$kind] = ['current' => $current, 'retained' => $handlers, 'deprecated' => $closed];
        }

        return $policies;
    }

    public static function admit(string $kind): int
    {
        $policy = self::policies()[$kind] ?? null;
        $version = $policy['current'] ?? 1;
        self::assertAdmissible($kind, $version);

        return $version;
    }

    public static function assertAdmissible(string $kind, int $version): void
    {
        if (in_array($version, self::policies()[$kind]['deprecated'] ?? [], true)) {
            throw self::deprecated();
        }
    }

    public static function deprecated(): ResourceOperationException
    {
        return new ResourceOperationException('operation.version_deprecated', 'This Gateway cannot admit the selected Operation version.', 409);
    }

    public static function pinned(Operation $operation): int
    {
        return $operation->operation_version ?? 1;
    }
}
