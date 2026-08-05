<?php

declare(strict_types=1);

namespace Orbit\Core\Php;

/**
 * Classifies an installed host PHP CLI binary against the requested variant.
 */
final class PhpCliRuntimeClassifier
{
    public const string KIND_STANDARD = 'standard';

    public const string KIND_COVERAGE = 'coverage';

    public const string KIND_COVERAGE_BROKEN = 'coverage_broken';

    public const string KIND_MISSING = 'missing';

    /**
     * @param  array{
     *     present?: bool,
     *     patch?: string|null,
     *     expected_patch?: string|null,
     *     extension_loaded_pcov?: bool|null,
     *     function_exists_pcov_start?: bool|null,
     *     pcov_enabled?: bool|null,
     *     ri_pcov_ok?: bool|null
     * }  $observed
     * @return array{
     *     kind: string,
     *     ok: bool,
     *     summary: string,
     *     detail: array<string, mixed>
     * }
     */
    public function classify(PhpCliVariant $expected, array $observed): array
    {
        $present = ($observed['present'] ?? false) === true;

        if (! $present) {
            return [
                'kind' => self::KIND_MISSING,
                'ok' => false,
                'summary' => 'PHP CLI binary is missing.',
                'detail' => $observed,
            ];
        }

        $patch = is_string($observed['patch'] ?? null) ? $observed['patch'] : null;
        $expectedPatch = is_string($observed['expected_patch'] ?? null) ? $observed['expected_patch'] : null;
        $pcovLoaded = ($observed['extension_loaded_pcov'] ?? false) === true;
        $pcovStart = ($observed['function_exists_pcov_start'] ?? false) === true;
        $pcovEnabled = ($observed['pcov_enabled'] ?? false) === true;
        $riOk = ($observed['ri_pcov_ok'] ?? false) === true;
        $patchOk = $expectedPatch === null || $patch === $expectedPatch;

        if ($expected === PhpCliVariant::Standard) {
            // Align with build verification: standard means PCOV is entirely absent.
            $pcovAbsent = ! $pcovLoaded && ! $pcovStart && ! $pcovEnabled && ! $riOk;

            if ($patchOk && $pcovAbsent) {
                return [
                    'kind' => self::KIND_STANDARD,
                    'ok' => true,
                    'summary' => 'Standard PHP CLI matches the expected patch and has no PCOV.',
                    'detail' => $observed,
                ];
            }

            return [
                'kind' => $pcovAbsent ? self::KIND_STANDARD : self::KIND_COVERAGE,
                'ok' => false,
                'summary' => $pcovAbsent
                    ? 'Standard PHP CLI patch does not match gateway intent.'
                    : 'Standard PHP CLI unexpectedly includes PCOV.',
                'detail' => $observed,
            ];
        }

        $coverageOk = $patchOk && $pcovLoaded && $pcovStart && $pcovEnabled && $riOk;

        if ($coverageOk) {
            return [
                'kind' => self::KIND_COVERAGE,
                'ok' => true,
                'summary' => 'Coverage PHP CLI matches the expected patch with a working PCOV.',
                'detail' => $observed,
            ];
        }

        return [
            'kind' => self::KIND_COVERAGE_BROKEN,
            'ok' => false,
            'summary' => 'Coverage PHP CLI is missing a working PCOV or does not match the expected patch.',
            'detail' => $observed,
        ];
    }
}
