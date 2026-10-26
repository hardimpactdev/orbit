<?php

declare(strict_types=1);

namespace Tests\Support;

/** Healthy native pool answers for tests of Gateway intent. Filesystem behavior is covered in Rust. */
final class SharedPhpPoolAnswers
{
    public static function register(FakeAgentCommandRunner $agents): FakeAgentCommandRunner
    {
        return $agents
            ->answer('php.inspect-shared-pools', static fn (array $input): array => [
                'versions' => array_keys((array) $input['configurations']),
                'missing_pools' => [],
            ])
            ->answer('php.converge-shared-pools', ['changed' => false, 'missing_pools' => []]);
    }
}
