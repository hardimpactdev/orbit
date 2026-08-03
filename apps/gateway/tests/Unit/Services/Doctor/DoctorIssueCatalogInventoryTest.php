<?php

declare(strict_types=1);

use App\Enums\DoctorIssueDisposition;
use App\Exceptions\DoctorUncataloguedIssueException;
use App\Services\Doctor\DoctorIssueCatalog;
use App\Services\Doctor\DoctorReportRunner;

/**
 * Protection inventory: every emitted Doctor issue code must be explicitly
 * classified, and every genuine-drift code must declare a restorer. Unknown
 * codes fail closed — never invent disposition from name heuristics.
 */
it('classifies every family-doctor contract issue code explicitly', function (): void {
    $docCodes = doctor_family_doc_issue_codes();
    $catalogCodes = DoctorIssueCatalog::codes();

    $missing = array_values(array_diff($docCodes, $catalogCodes));

    expect($missing)
        ->toBeEmpty('Uncatalogued family-doctor issue codes: '.implode(', ', $missing));
});

it('requires a declared restore action for every genuine-drift catalog entry', function (): void {
    $missingRestorers = [];

    foreach (DoctorIssueCatalog::definitions() as $code => $definition) {
        if ($definition->disposition !== DoctorIssueDisposition::GenuineDrift) {
            continue;
        }

        if (! is_string($definition->restoreAction) || $definition->restoreAction === '') {
            $missingRestorers[] = $code;
        }
    }

    expect($missingRestorers)
        ->toBeEmpty('Genuine drift without restore action: '.implode(', ', $missingRestorers));
});

it('marks only genuine-drift catalog entries restorable', function (): void {
    foreach (DoctorIssueCatalog::definitions() as $code => $definition) {
        $restorable = DoctorIssueCatalog::isRestorable($code);

        if ($definition->disposition === DoctorIssueDisposition::GenuineDrift) {
            expect($restorable)->toBeTrue("Expected {$code} to be restorable");
        } else {
            expect($restorable)->toBeFalse("Expected {$code} not to be restorable");
        }
    }
});

it('fails closed for uncatalogued issue codes instead of inventing a disposition', function (): void {
    expect(fn () => DoctorIssueCatalog::require('doctor.not_a_real_issue_code'))
        ->toThrow(DoctorUncataloguedIssueException::class);
});

it('owns definitions through family providers rather than one heuristic map', function (): void {
    $providers = DoctorIssueCatalog::providers();

    expect($providers)
        ->not->toBeEmpty()
        ->and(count($providers))
        ->toBeGreaterThan(1);

    foreach ($providers as $provider) {
        expect($provider->definitions())->not->toBeEmpty();
    }
});

it('does not keep name-substring fallback classification in the doctor runner', function (): void {
    $runnerPath = (new ReflectionClass(DoctorReportRunner::class))->getFileName();
    expect($runnerPath)->toBeString();
    $source = file_get_contents((string) $runnerPath);
    expect($source)->toBeString();

    expect($source)
        ->not->toContain('fallbackDisposition')
        ->and($source)
        ->not->toContain("str_contains(\$code, 'probe_failed')")
        ->and($source)
        ->not->toContain("str_contains(\$code, 'record_incomplete')");
});

/**
 * @return list<string>
 */
function doctor_family_doc_issue_codes(): array
{
    $docsRoot = dirname(__DIR__, 5).'/docs/content/domains';
    $paths = glob($docsRoot.'/*/*-doctor.md') ?: [];
    $codes = [];

    foreach ($paths as $path) {
        $text = file_get_contents($path);
        expect($text)->toBeString();

        if (! preg_match('/## [^\n]*Issue Codes\n(.*?)(?=\n## |\z)/s', $text, $section)) {
            continue;
        }

        preg_match_all('/^\|\s*`([a-z][a-z0-9_.]+)`\s*\|/m', $section[1], $matches);

        foreach ($matches[1] as $code) {
            $codes[$code] = true;
        }
    }

    $list = array_keys($codes);
    sort($list);

    return $list;
}
