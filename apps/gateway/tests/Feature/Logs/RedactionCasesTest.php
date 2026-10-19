<?php

declare(strict_types=1);

use App\Domain\Problems\ProblemLogParser;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

describe('the redaction cases shared with the Node agent', function (): void {
    it('redact every case exactly as the Node agent does', function (): void {
        $path = dirname(base_path()).'/agent/tests/redaction_cases.json';

        expect(is_file($path))->toBeTrue();

        /** @var list<array{name: string, input: string|list<string|array{text: string, repeat: int}>, expected: string|list<string|array{text: string, repeat: int}>}> $cases */
        $cases = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        $sanitizer = app(CommandActivityInputSanitizer::class);
        $expand = static function (string|array $text): string {
            return is_string($text) ? $text : implode('', array_map(
                static fn (string|array $part): string => is_string($part) ? $part : str_repeat($part['text'], $part['repeat']),
                $text,
            ));
        };
        Log::spy();
        $limit = ini_get('pcre.backtrack_limit');

        expect($cases)->not->toBeEmpty();

        foreach ($cases as $case) {
            expect($sanitizer->redactText($expand($case['input'])))->toBe($expand($case['expected']), $case['name']);
            expect(ini_get('pcre.backtrack_limit'))->toBe($limit);
        }

        Log::shouldNotHaveReceived('error');
    });
});

it('preserves output and redacts secrets after a 100 KiB hyphenated run without logging an error', function (): void {
    Log::spy();
    $prefix = str_repeat('a-', 50 * 1024).": x\n";
    $output = app(CommandActivityInputSanitizer::class)->redactText($prefix."password: sentinel-password\nTOKEN=sentinel-token\nFAILED last line");

    expect($output)->not->toBe('Output withheld: redaction failed.');
    expect(substr($output, strlen($prefix)))->toBe("password: [REDACTED]\nTOKEN=[REDACTED]\nFAILED last line");
    Log::shouldNotHaveReceived('error');
});

it('still withholds text and logs a sanitized error when PCRE fails', function (): void {
    $stream = fopen('php://memory', 'w+');
    $handler = new StreamHandler($stream);
    $handler->setFormatter(new LineFormatter(dateFormat: 'Y-m-d H:i:s', allowInlineLineBreaks: true, includeStacktraces: true));
    $buffer = new BufferHandler($handler);
    Log::swap(new Logger(new MonologLogger('testing', [$buffer])));
    $sanitizer = app(CommandActivityInputSanitizer::class);
    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_set('pcre.jit', '0');
    $recursion = ini_set('pcre.recursion_limit', '1');

    try {
        $output = $sanitizer->redactText('password: sentinel-password TOKEN=sentinel-token');
    } finally {
        ini_set('pcre.recursion_limit', $recursion);
        ini_set('pcre.jit', $jit);
    }

    expect($output)->toBe('Output withheld: redaction failed.');
    expect(ini_get('pcre.backtrack_limit'))->toBe($limit);
    $buffer->flush();
    rewind($stream);
    $record = stream_get_contents($stream);
    fclose($stream);
    expect($record)->toContain('.ERROR:', '"pcre_error":'.PREG_RECURSION_LIMIT_ERROR)
        ->not->toContain('sentinel-password')
        ->not->toContain('sentinel-token');
    $signal = app(ProblemLogParser::class)->signal($record);
    expect($signal)->not->toBeNull()
        ->and($signal->excerpt)->toBe('Output withheld: redaction failed.');
});
