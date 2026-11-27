<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/**
 * What a preceding-release write decision read of the package, by that release's own decision code (`decide`, `before`, `candidate`).
 * Its refused decision repeats the admitted version whenever the package was absent, and a ready one records absence as none; its receipt
 * repeats the admitted version for either (docs/reference/tools.md#upgrade-from-the-preceding-release).
 */
final class PrecedingToolDecision
{
    /** Refusals before the inventory was read: the manager's scope, or an unreadable inventory (or an update's absent package). */
    private const array UNREAD = ['tool.manager_unavailable', 'tool.version_probe_failed'];

    /** Refusals of the candidate, reached only after `before` let the write go on: a reinstall's absent package, an update's present one. */
    private const array CANDIDATE = ['tool.candidate_version_probe_failed', 'tool.candidate_version_unavailable', 'tool.candidate_version_unparseable',
        'tool.version_constraint_blocked'];

    /**
     * The installed version the decision read, `null` for absence, or no reading at all.
     *
     * @param  bool  $ready  the decision let its write go on: a failure at or after its package step, or a ready decision
     * @param  ?string  $recorded  the version the decision or its receipt records; the caller reads no decision whose record lacks the field
     * @param  ?string  $admitted  the version the request was admitted with
     * @return array{version: ?string}|null
     */
    public static function reading(string $kind, string $manager, string $platform, bool $ready, ?string $errorCode, ?string $recorded, ?string $admitted): ?array
    {
        if ($kind === 'tool.remove') {
            // A removal's decision returns no version: the receipt only repeats the admitted one.
            return null;
        }
        $install = $kind === 'tool.install';
        if ($ready) {
            // An update writes only a present package, a reinstall only an absent one: a present reinstall returned unchanged.
            return ['version' => $install ? null : $recorded];
        }
        if ($errorCode === null || in_array($errorCode, self::UNREAD, true)) {
            return null;
        }
        if ($manager === ToolManagerName::BrewCask->value && $platform === 'linux' && $errorCode === 'tool.candidate_version_probe_failed') {
            // A Linux cask's refresh refuses right after a successful read, present or absent, and repeats the admitted version for an
            // absent package: none recorded is absence (a present package has a version), and only the admitted version itself is ambiguous.
            return $recorded !== null && $recorded === $admitted ? null : ['version' => $recorded];
        }
        if (in_array($errorCode, self::CANDIDATE, true)) {
            return ['version' => $install ? null : $recorded];
        }

        // Every other refusal (`before`, the installed-version checks) found the package present.
        return ['version' => $recorded];
    }
}
