<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
use App\Services\RemoteShell\RemoteShellSuccessData;

it('exposes typed protocol failures for ambiguous success envelopes', function (string $stdout): void {
    $result = new RemoteShellResult(0, $stdout, '', 1);

    expect(fn (): array => RemoteShellSuccessData::fromJsonEnvelopeOrFail($result))
        ->toThrow(RemoteShellProtocolException::class);
})->with([
    'empty output' => '',
    'malformed JSON' => '{"success":',
    'missing success.data' => '{"success":{"meta":[]}}',
    'invalid success.data' => '{"success":{"data":"invalid","meta":[]}}',
]);

it('keeps the explicit lossy parser behavior for unmigrated callers', function (): void {
    $result = new RemoteShellResult(0, '{"success":', '', 1);

    expect(RemoteShellSuccessData::fromJsonEnvelope($result))->toBeEmpty();
});
