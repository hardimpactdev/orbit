<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\Processes\ProtectedInput;
use Tests\Support\ProcessInput;

it('stores sensitive input in a protected temporary file without exposing it in debug output', function (): void {
    $sensitiveValue = 'ALPHA=opaque-value-with-spaces';
    $input = ProtectedInput::fromString($sensitiveValue);
    $stream = $input->stream();
    $metadata = stream_get_meta_data($stream);
    $statistics = fstat($stream);
    $debugOutput = json_encode($input->__debugInfo(), JSON_THROW_ON_ERROR);

    expect($statistics)
        ->toBeArray()
        ->and($statistics['mode'] & 0o777)
        ->toBe(0o600)
        ->and(stream_get_contents($stream))
        ->toBe($sensitiveValue)
        ->and($debugOutput)
        ->not
        ->toContain($sensitiveValue)
        ->toContain('[PROTECTED]');

    $path = $metadata['uri'];
    $input->close();

    expect($path)
        ->toBeString()
        ->and(file_exists($path))
        ->toBeFalse();
});

it('keeps a non-seekable input stream open until it is closed', function (): void {
    $input = ProtectedInput::holdOpen();
    $stream = $input->stream();

    expect(is_resource($stream))->toBeTrue();
    expect(stream_get_meta_data($stream)['eof'])->toBeFalse();

    $input->close();

    expect(is_resource($stream))->toBeFalse();
});

it('prefixes a private open file without copying it and leaves it available for replay', function (): void {
    $file = tmpfile();
    fwrite($file, "\0binary\xff");
    $path = stream_get_meta_data($file)['uri'];
    try {
        $body = ProtectedInput::openFile($path);
        $input = ProtectedInput::prefixed($body, "header\n", credential: true);
        $chunks = iterator_to_array($input->chunks());
        expect($chunks[0])->toBe("header\n")
            ->and(fstat($chunks[1])['ino'])->toBe(fstat($file)['ino'])
            ->and($input->carriesGitCredential())->toBeTrue()
            ->and(ProcessInput::contents($input))->toBe("header\n\0binary\xff")
            ->and(print_r($input, true))->not->toContain('header');
        $input->close();
        expect(fn () => iterator_to_array($input->chunks()))->toThrow(LogicException::class);
        $retry = ProtectedInput::prefixed($body, "retry\n");
        expect(ProcessInput::contents($retry))->toBe("retry\n\0binary\xff");
    } finally {
        fclose($file);
    }
});

it('borrows an unlinked private staging stream without closing its owner', function (): void {
    $file = tmpfile();
    $path = stream_get_meta_data($file)['uri'];
    unlink($path);
    fwrite($file, 'binary fixture');
    $input = AgentBinaryInput::fromStream($file, 14);
    expect($input->bytes)->toBe(14)->and(file_exists($path))->toBeFalse();
    unset($input);
    expect(is_resource($file))->toBeTrue();
    fclose($file);
});
