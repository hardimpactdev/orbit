<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAnalysisReport;

/** @return array<string, mixed> */
function analysis_report_fixture(): array
{
    return ['schema' => 1, 'execution_id' => str_repeat('a', 32), 'plan_sha256' => str_repeat('b', 64),
        'base' => str_repeat('c', 40), 'head' => str_repeat('d', 40), 'tree' => str_repeat('e', 40),
        'status' => 'ready', 'diagnostics' => [], 'report' => ['schema' => 'fixture.repository.v1', 'data' => (object) ['complete' => 'é']]];
}

it('validates complete exact UTF-8 evidence up to the byte and depth bounds', function (): void {
    $value = analysis_report_fixture();
    $value['report']['data'] = (object) ['text' => ''];
    $bytes = json_encode($value, JSON_THROW_ON_ERROR);
    $value['report']['data']->text = str_repeat('x', TaskAnalysisReport::MaxBytes - strlen($bytes));
    $bytes = json_encode($value, JSON_THROW_ON_ERROR);
    expect(strlen($bytes))->toBe(65536)->and(TaskAnalysisReport::validate($bytes, $value)['report']['data']['text'])->toBe($value['report']['data']->text);
    expect(fn () => TaskAnalysisReport::validate($bytes.' ', $value))->toThrow(ResourceOperationException::class, 'exceeds 64 KiB');
    $data = (object) [];
    for ($i = 0; $i < 21; $i++) {
        $data = (object) ['nested' => $data];
    }
    $value['report']['data'] = $data;
    expect(TaskAnalysisReport::validate(json_encode($value, JSON_THROW_ON_ERROR), $value)['status'])->toBe('ready');
    $value['report']['data'] = (object) ['nested' => $data];
    expect(fn () => TaskAnalysisReport::validate(json_encode($value, JSON_THROW_ON_ERROR), $value))->toThrow(ResourceOperationException::class, 'depth');
});

it('refuses complete malformed or mismatched reports rather than accepting a green prefix', function (Closure $change): void {
    $identity = analysis_report_fixture();
    $value = $identity;
    $change($value);
    expect(fn () => TaskAnalysisReport::validate(json_encode($value, JSON_THROW_ON_ERROR), $identity))->toThrow(ResourceOperationException::class);
})->with([
    'unknown envelope field' => [fn (&$value) => $value['extra'] = true],
    'missing envelope field' => [function (&$value): void {
        unset($value['tree']);
    }],
    'wrong schema' => [fn (&$value) => $value['schema'] = 2],
    'unsupported status' => [fn (&$value) => $value['status'] = 'changed'],
    'wrong execution' => [fn (&$value) => $value['execution_id'] = str_repeat('f', 32)],
    'wrong plan' => [fn (&$value) => $value['plan_sha256'] = str_repeat('f', 64)],
    'wrong base' => [fn (&$value) => $value['base'] = str_repeat('f', 40)],
    'wrong head' => [fn (&$value) => $value['head'] = str_repeat('f', 40)],
    'wrong tree' => [fn (&$value) => $value['tree'] = str_repeat('f', 40)],
    'diagnostics object' => [fn (&$value) => $value['diagnostics'] = (object) []],
    'diagnostic not object' => [fn (&$value) => $value['diagnostics'] = ['error']],
    'diagnostic missing field' => [fn (&$value) => $value['diagnostics'] = [(object) ['code' => 'failure']]],
    'diagnostic wrong type' => [fn (&$value) => $value['diagnostics'] = [(object) ['code' => 'failure', 'message' => []]]],
    'report missing field' => [fn (&$value) => $value['report'] = (object) ['schema' => 'fixture.v1']],
    'report data list' => [fn (&$value) => $value['report']['data'] = []],
]);

it('rejects duplicate keys at every depth, trailing data, invalid encoding and non-finite numbers', function (Closure $change): void {
    $value = analysis_report_fixture();
    $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    expect(fn () => TaskAnalysisReport::validate($change($bytes), $value))->toThrow(ResourceOperationException::class);
})->with([
    'duplicate root' => [fn ($bytes) => str_replace('"schema":1', '"schema":2,"schema":1', $bytes)],
    'duplicate payload' => [fn ($bytes) => str_replace('"complete":"é"', '"complete":false,"complete":"é"', $bytes)],
    'trailing object' => [fn ($bytes) => $bytes.'{}'],
    'truncated' => [fn ($bytes) => substr($bytes, 0, -1)],
    'invalid UTF-8' => [fn ($bytes) => str_replace('é', "\xff", $bytes)],
    'unpaired surrogate' => [fn ($bytes) => str_replace('é', '\\ud800', $bytes)],
    'non-finite payload' => [fn ($bytes) => str_replace('"complete":"é"', '"complete":1e999', $bytes)],
]);

it('accepts only closed typed adapter failures with safe diagnostic values', function (string $bytes, string $code, string $message): void {
    try {
        TaskAnalysisReport::rejectFailure($bytes);
        test()->fail('Expected a typed analysis failure.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code)->and($exception->status)->toBe(422)
            ->and($exception->getMessage())->toContain($message)->not->toContain('secret-value');
    }
})->with([
    'overflow with exact required size' => ['{"schema":1,"error":{"code":"tasks.analysis_report_too_large","required_bytes":123456}}', 'tasks.analysis_report_too_large', 'requires 123456 bytes; the maximum is 65536'],
    'missing tooling without raw diagnostics' => ['{"schema":1,"error":{"code":"tasks.analysis_unavailable","required_bytes":null}}', 'tasks.analysis_unavailable', 'tooling is unavailable'],
    'unknown code' => ['{"schema":1,"error":{"code":"secret-value","required_bytes":null}}', 'tasks.analysis_report_invalid', 'Invalid analysis failure code'],
    'false overflow' => ['{"schema":1,"error":{"code":"tasks.analysis_report_too_large","required_bytes":65536}}', 'tasks.analysis_report_invalid', 'Invalid analysis failure code'],
    'negative size' => ['{"schema":1,"error":{"code":"tasks.analysis_report_too_large","required_bytes":-1}}', 'tasks.analysis_report_invalid', 'Invalid analysis failure code'],
    'string size' => ['{"schema":1,"error":{"code":"tasks.analysis_report_too_large","required_bytes":"70000"}}', 'tasks.analysis_report_invalid', 'Invalid analysis failure code'],
    'float size' => ['{"schema":1,"error":{"code":"tasks.analysis_report_too_large","required_bytes":70000.0}}', 'tasks.analysis_report_invalid', 'Invalid analysis failure code'],
    'missing size' => ['{"schema":1,"error":{"code":"tasks.analysis_report_too_large"}}', 'tasks.analysis_report_invalid', 'fields differ'],
    'unavailable with size' => ['{"schema":1,"error":{"code":"tasks.analysis_unavailable","required_bytes":70000}}', 'tasks.analysis_report_invalid', 'Invalid analysis failure code'],
    'wrong schema' => ['{"schema":2,"error":{"code":"tasks.analysis_unavailable","required_bytes":null}}', 'tasks.analysis_report_invalid', 'version'],
    'extra diagnostic cannot inject raw secrets' => ['{"schema":1,"error":{"code":"tasks.analysis_unavailable","required_bytes":null,"message":"secret-value"}}', 'tasks.analysis_report_invalid', 'fields differ'],
    'mixed report and failure' => ['{"schema":1,"error":{"code":"tasks.analysis_unavailable","required_bytes":null},"report":{}}', 'tasks.analysis_report_invalid', 'fields differ'],
    'duplicate nested code' => ['{"schema":1,"error":{"code":"tasks.analysis_unavailable","code":"tasks.analysis_unavailable","required_bytes":null}}', 'tasks.analysis_report_invalid', 'duplicate'],
    'trailing bytes' => ['{"schema":1,"error":{"code":"tasks.analysis_unavailable","required_bytes":null}}{}', 'tasks.analysis_report_invalid', 'Invalid complete'],
]);

it('does not classify an ordinary report as a typed adapter failure', function (): void {
    $value = analysis_report_fixture();
    $bytes = json_encode($value, JSON_THROW_ON_ERROR);
    TaskAnalysisReport::rejectFailure($bytes);
    expect(TaskAnalysisReport::validate($bytes, $value)['status'])->toBe('ready');
});
