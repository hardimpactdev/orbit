<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskAnalysisPlan;

function analysis_plan_fixture(): stdClass
{
    return json_decode('{"id":"feature-2068","revision":1,"source":{"brief_id":"widget-brief","revision_sha256":"'.str_repeat('a', 64).'"},"asserted_by":"planning-owner","complete":true,"paths":[{"change":"add","path":"apps/gateway/new.php"}]}', flags: JSON_THROW_ON_ERROR);
}

function analysis_plan_digest(stdClass $plan): string
{
    $ordered = function (mixed $value) use (&$ordered): mixed {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);

            return (object) array_map($ordered, $fields);
        }

        return is_array($value) ? array_map($ordered, $value) : $value;
    };

    return hash('sha256', json_encode($ordered($plan), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS));
}

it('uses the cross-language RFC 8785 manifest fixture and flattens rename endpoints once', function (): void {
    $value = analysis_plan_fixture();
    $canonical = '{"asserted_by":"planning-owner","complete":true,"id":"feature-2068","paths":[{"change":"add","path":"apps/gateway/new.php"}],"revision":1,"source":{"brief_id":"widget-brief","revision_sha256":"'.str_repeat('a', 64).'"}}';
    $plan = TaskAnalysisPlan::parse($value, hash('sha256', $canonical));
    expect($plan->canonical)->toBe($canonical)->and($plan->paths)->toBe(['apps/gateway/new.php']);
    $value->paths = [(object) ['change' => 'rename', 'from' => 'old.php', 'path' => 'new.php']];
    expect(TaskAnalysisPlan::parse($value, analysis_plan_digest($value))->paths)->toBe(['old.php', 'new.php']);
    $value->asserted_by = "é\u{2028}";
    expect(TaskAnalysisPlan::parse($value, analysis_plan_digest($value))->canonical)->toContain('"asserted_by":"é'."\u{2028}".'"');
});

it('preserves numeric file names as exact string endpoints', function (): void {
    $value = analysis_plan_fixture();
    $value->paths = [(object) ['change' => 'rename', 'from' => '01', 'path' => '123']];
    expect(TaskAnalysisPlan::parse($value, analysis_plan_digest($value))->paths)->toBe(['01', '123']);
});

it('rejects unsafe paths without trimming, repair, or partial inventory', function (string $path): void {
    expect(fn () => TaskAnalysisPlan::path($path))->toThrow(InvalidArgumentException::class);
})->with(['', '/absolute.php', 'C:/drive.php', 'C:drive.php', 'a\\b.php', './a.php', 'a/../b.php', 'a//b.php', '.git/config', 'a/.git/config', 'a/', ' a.php', 'a.php ', "\u{00a0}a.php", "a.php\u{2003}", "a\n.php", "a\u{0085}.php", 'a*.php', 'a?.php', 'a[0].php', str_repeat('a', 513)]);

it('does not reject ordinary UTF-8 file names as shell programs or glob expansions', function (): void {
    TaskAnalysisPlan::path('nested/a name-é.php');
    TaskAnalysisPlan::path('nested/name:version.php');
    expect(true)->toBeTrue();
});

it('rejects wrong types, unknown fields, conflicts, unsorted plans, and overflow atomically', function (Closure $change): void {
    $value = analysis_plan_fixture();
    $change($value);
    expect(fn () => TaskAnalysisPlan::parse($value, analysis_plan_digest($value)))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown field' => [fn ($plan) => $plan->extra = true],
    'false completeness' => [fn ($plan) => $plan->complete = false],
    'fractional revision' => [fn ($plan) => $plan->revision = 1.5],
    'unsafe integer' => [fn ($plan) => $plan->revision = 9007199254740992],
    'empty paths' => [fn ($plan) => $plan->paths = []],
    'unknown change' => [fn ($plan) => $plan->paths[0]->change = 'copy'],
    'spurious from' => [fn ($plan) => $plan->paths[0]->from = 'old.php'],
    'missing rename endpoint' => [fn ($plan) => $plan->paths[0]->change = 'rename'],
    'conflicting path' => [fn ($plan) => $plan->paths[] = (object) ['change' => 'delete', 'path' => 'apps/gateway/new.php']],
    'reverse byte order' => [fn ($plan) => $plan->paths[] = (object) ['change' => 'modify', 'path' => 'a.php']],
    '257 entries' => [function ($plan): void {
        $plan->paths = array_map(fn ($i) => (object) ['change' => 'add', 'path' => sprintf('%03d.php', $i)], range(0, 256));
    }],
    'canonical byte overflow' => [fn ($plan) => $plan->source->brief_id = str_repeat('x', TaskAnalysisPlan::ManifestBytes)],
]);
