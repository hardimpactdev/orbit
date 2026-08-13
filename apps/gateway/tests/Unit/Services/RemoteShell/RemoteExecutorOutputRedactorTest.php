<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Services\RemoteShell\LocalExecutorTransportOptions;
use App\Services\RemoteShell\RemoteExecutorOutputRedactor;
use Orbit\Core\Security\SecretSummaryRedactor;

it(
    'redacts operation-token output variant :dataset',
    function (Closure $output, string $expected): void {
        $token = hash('sha256', 'operation-402');
        $redactor = remote_executor_output_redactor();

        expect($redactor->summarizeOutput($output($token), $token))->toBe($expected);
    },
)->with([
    'no spaces around equals' => [
        static fn (#[SensitiveParameter] string $token): string => "--operation-token={$token}",
        '--operation-token=<redacted>',
    ],
    'space before equals' => [
        static fn (#[SensitiveParameter] string $token): string => "--operation-token ={$token}",
        '--operation-token=<redacted>',
    ],
    'space after equals' => [
        static fn (#[SensitiveParameter] string $token): string => "--operation-token= {$token}",
        '--operation-token=<redacted>',
    ],
    'spaces around equals' => [
        static fn (#[SensitiveParameter] string $token): string => "--operation-token = {$token}",
        '--operation-token=<redacted>',
    ],
    'whitespace separator' => [
        static fn (#[SensitiveParameter] string $token): string => "--operation-token {$token}",
        '--operation-token=<redacted>',
    ],
    'double quoted value' => [
        static fn (#[SensitiveParameter] string $token): string => "--operation-token=\"{$token}\"",
        '--operation-token=<redacted>',
    ],
    'single quoted value' => [
        static fn (#[SensitiveParameter] string $token): string => "--operation-token='{$token}'",
        '--operation-token=<redacted>',
    ],
    'at end of string' => [
        static fn (#[SensitiveParameter] string $token): string => "ending --operation-token={$token}",
        'ending --operation-token=<redacted>',
    ],
]);

it('redacts secrets before truncating output summaries', function (): void {
    $token = hash('sha256', 'operation-token-that-crosses-the-boundary');
    $output = str_repeat('p', 4_080).$token.str_repeat('s', 300);

    $summary = remote_executor_output_redactor()->summarizeOutput($output, $token);

    expect($summary)
        ->not
        ->toContain($token)
        ->toContain('<redacted>')
        ->and(strlen($summary))
        ->toBe(4_107)
        ->and(str_ends_with($summary, '[truncated]'))
        ->toBeTrue();
});

it('suppresses output when requested', function (): void {
    expect(remote_executor_output_redactor()->summarizeOutput('secret output', 'token', suppress: true))
        ->toBe('<suppressed>');
});

it('sanitizes operation tokens in failed shell results', function (): void {
    $token = hash('sha256', 'operation-402');
    $result = new RemoteShellResult(
        exitCode: 13,
        stdout: "stdout {$token}",
        stderr: "--operation-token='{$token}'",
        durationMs: 42,
    );

    $sanitized = remote_executor_output_redactor()->sanitizeResult($result, $token);

    expect($sanitized)
        ->not
        ->toBe($result)
        ->and($sanitized->exitCode)
        ->toBe(13)
        ->and($sanitized->stdout)
        ->toBe('stdout <redacted>')
        ->and($sanitized->stderr)
        ->toBe('--operation-token=<redacted>')
        ->and($sanitized->durationMs)
        ->toBe(42);
});

it('redacts explicit command options from audit lines and exception data', function (): void {
    $token = hash('sha256', 'operation-402');
    $privateKey = hash('sha256', 'private-key-material-402');
    $options = LocalExecutorTransportOptions::fromArray([
        'redact_command_options' => ['private-key'],
    ]);
    $throwable = new class(
        "transport --private-key={$privateKey} token {$token}",
        [
            'private-key' => $privateKey,
            'nested' => [
                'message' => "failed with {$privateKey}",
                'resource' => new stdClass,
            ],
        ],
    ) extends RuntimeException {
        public function __construct(
            string $message,
            public array $meta,
        ) {
            parent::__construct($message);
        }
    };
    $commandOptions = ['private-key' => $privateKey];
    $redactor = remote_executor_output_redactor();

    expect($redactor->redactCommandOptionsInLine(
        "orbit internal:verify --private-key='{$privateKey}'",
        $options,
    ))
        ->toBe('orbit internal:verify --private-key=<redacted>')
        ->and($redactor->exceptionMessageSummary($throwable, $token, $options, $commandOptions))
        ->toBe('transport --private-key=<redacted> token <redacted>')
        ->and($redactor->exceptionMetadata($throwable, $token, $options, $commandOptions))
        ->toBe([
            'private-key' => '<redacted>',
            'nested' => [
                'message' => 'failed with <redacted>',
                'resource' => '<redacted>',
            ],
        ]);
});

it('suppresses exception messages when command output is sensitive', function (): void {
    $throwable = new RuntimeException('transport leaked api_token=secret-value');
    $options = LocalExecutorTransportOptions::fromArray(['redact_stdout' => true]);

    expect(remote_executor_output_redactor()->exceptionMessageSummary($throwable, 'token', $options, []))
        ->toBe('<suppressed>');
});

function remote_executor_output_redactor(): RemoteExecutorOutputRedactor
{
    return new RemoteExecutorOutputRedactor(new SecretSummaryRedactor);
}
