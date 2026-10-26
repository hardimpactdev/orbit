<?php

declare(strict_types=1);

namespace Tests\Support;

/** Native image results for tests of Gateway build intent. */
final class RecipeImageAnswers
{
    public const string Boot = '11111111-1111-4111-8111-111111111111';

    /** @return array<string, mixed> */
    public static function observation(string $state, string $invocation = '11111111111111111111111111111111', ?string $reason = null, ?int $exit = null, string $log = ''): array
    {
        return ['boot_id' => self::Boot, 'unit' => 'orbit-recipe-image-test.service', 'state' => $state,
            'invocation_id' => $state === 'missing' ? null : $invocation,
            'exit_code' => $exit, 'reason' => $reason, 'log_tail' => $log];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function success(string $command, array $input): array
    {
        return match ($command) {
            'image.inspect-phase' => self::observation('missing'),
            'image.start-phase', 'image.restart-phase' => self::observation('complete'),
            'pi.receive-artifact' => ['received_bytes' => $input['total'], 'verified' => true],
            default => ['ready' => true],
        };
    }
}
