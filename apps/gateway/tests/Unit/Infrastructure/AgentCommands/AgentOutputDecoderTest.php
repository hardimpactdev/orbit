<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentOutputDecoder;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;

it('decodes fragmented binary chunks and keeps binary bytes out of diagnostics', function (): void {
    $file = tmpfile();
    $sink = new AgentBinaryOutput($file, 1000);
    $decoder = new AgentOutputDecoder('export', $sink, 1000);
    $bytes = "\0PACK\n{\"ok\":true}\xff\n";
    $wire = '{"stream_bytes":'.strlen($bytes)."}\n".$bytes.'{"ok":true,"data":{"bytes":'.strlen($bytes)."}}\n";
    foreach (str_split($wire, 3) as $fragment) {
        $decoder->consume(new ProcessOutput(ProcessOutputStream::Stdout, $fragment));
    }
    $decoder->consume(new ProcessOutput(ProcessOutputStream::Stderr, 'export diagnostic'));
    $result = $decoder->finish(new CommandResult(0, 'discard this raw transport tail', '', 9, true));
    rewind($file);

    expect(stream_get_contents($file))->toBe($bytes)
        ->and($sink->bytes())->toBe(strlen($bytes))
        ->and(json_decode($result->stdout, true))->toBe(['ok' => true, 'data' => ['bytes' => strlen($bytes)]])
        ->and($result->stderr)->toBe('export diagnostic')
        ->and($result->durationMs)->toBe(9)
        ->and($result->truncated)->toBeFalse();
    fclose($file);
});

it('refuses invalid framing without exposing stream contents', function (string $wire): void {
    $file = tmpfile();
    $decoder = new AgentOutputDecoder('export', new AgentBinaryOutput($file, 100_000), 100_000);

    expect(function () use ($decoder, $wire): void {
        $decoder->consume(new ProcessOutput(ProcessOutputStream::Stdout, $wire));
        $decoder->finish(new CommandResult(0, 'private-body', '', 0, false));
    })->toThrow(fn (AgentRunnerFailure $error): bool => $error->kind === AgentRunnerFailure::MalformedResult && $error->result === null && ! str_contains($error->getMessage(), 'private-body'));
    fclose($file);
})->with([
    'zero chunk' => "{\"stream_bytes\":0}\n",
    'negative chunk' => "{\"stream_bytes\":-1}\n",
    'large chunk' => "{\"stream_bytes\":65537}\n",
    'noninteger chunk' => "{\"stream_bytes\":1.5}\n",
    'extra field' => "{\"stream_bytes\":1,\"other\":true}\nx",
    'incomplete chunk' => "{\"stream_bytes\":2}\nx",
    'incomplete result' => '{"ok":true',
    'nonjson output' => "private-body\n",
    'output after result' => "{\"ok\":true,\"data\":{}}\nprivate-body",
    'mismatch after bytes' => "{\"stream_bytes\":1}\nx{\"ok\":false,\"error\":{\"code\":\"agent.version_mismatch\"}}\n",
]);

it('refuses bytes above either the command or caller limit before writing them', function (int $caller, int $command): void {
    $file = tmpfile();
    $sink = new AgentBinaryOutput($file, $caller);
    $decoder = new AgentOutputDecoder('export', $sink, $command);

    expect(fn () => $decoder->consume(new ProcessOutput(ProcessOutputStream::Stdout, "{\"stream_bytes\":3}\nabc")))
        ->toThrow(fn (AgentRunnerFailure $error): bool => $error->kind === AgentRunnerFailure::OutputLimit)
        ->and($sink->bytes())->toBe(0);
    fclose($file);
})->with([[2, 100], [100, 2]]);

it('bounds an unterminated header and refuses a lost sink', function (): void {
    $file = tmpfile();
    $sink = new AgentBinaryOutput($file, 100);
    $decoder = new AgentOutputDecoder('export', $sink, 100);
    expect(fn () => $decoder->consume(new ProcessOutput(ProcessOutputStream::Stdout, str_repeat('x', AgentTransport::ResultBytes + 1))))
        ->toThrow(fn (AgentRunnerFailure $error): bool => $error->kind === AgentRunnerFailure::MalformedResult);

    fclose($file);
    $decoder = new AgentOutputDecoder('export', $sink, 100);
    expect(fn () => $decoder->consume(new ProcessOutput(ProcessOutputStream::Stdout, "{\"stream_bytes\":1}\nx")))
        ->toThrow(fn (AgentRunnerFailure $error): bool => $error->kind === AgentRunnerFailure::OutputWrite);
});
