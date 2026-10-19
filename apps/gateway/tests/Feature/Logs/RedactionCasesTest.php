<?php

declare(strict_types=1);

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use Illuminate\Support\Facades\Log;

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

        expect($cases)->not->toBeEmpty();

        foreach ($cases as $case) {
            expect($sanitizer->redactText($expand($case['input'])))->toBe($expand($case['expected']), $case['name']);
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
    Log::spy();
    $sanitizer = app(CommandActivityInputSanitizer::class);
    $limit = ini_set('pcre.backtrack_limit', '0');

    try {
        $output = $sanitizer->redactText('password: sentinel-password TOKEN=sentinel-token');
    } finally {
        ini_set('pcre.backtrack_limit', $limit);
    }

    expect($output)->toBe('Output withheld: redaction failed.');
    Log::shouldHaveReceived('error')->with('Output withheld: redaction failed.', Mockery::on(
        static fn (array $context): bool => array_keys($context) === ['pcre_error', 'exception']
            && $context['pcre_error'] === PREG_BACKTRACK_LIMIT_ERROR
            && $context['exception'] instanceof RuntimeException
            && $context['exception']->getMessage() === 'Output withheld: redaction failed.',
    ));
});
