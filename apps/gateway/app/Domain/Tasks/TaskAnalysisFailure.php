<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use InvalidArgumentException;

/** Only validated, closed analysis diagnostics can cross the failed-operation result boundary. */
final class TaskAnalysisFailure extends ResourceOperationException
{
    public const int MaxRequiredBytes = 9007199254740991;

    /** @var array{schema: int, error: array{code: string, required_bytes: int|null}} */
    public readonly array $result;

    public function __construct(string $code, ?int $requiredBytes = null)
    {
        if (($code !== 'tasks.analysis_report_too_large' && $code !== 'tasks.analysis_unavailable')
            || ($requiredBytes !== null && ($code !== 'tasks.analysis_report_too_large' || $requiredBytes <= TaskAnalysisReport::MaxBytes || $requiredBytes > self::MaxRequiredBytes))) {
            throw new InvalidArgumentException('Invalid analysis failure code or required size.');
        }
        $this->result = ['schema' => 1, 'error' => ['code' => $code, 'required_bytes' => $requiredBytes]];
        parent::__construct($code, $code === 'tasks.analysis_unavailable'
            ? 'Required repository analysis tooling is unavailable.'
            : ($requiredBytes === null ? 'The complete analysis report exceeds 64 KiB.' : 'The complete analysis envelope requires '.$requiredBytes.' bytes; the maximum is '.TaskAnalysisReport::MaxBytes.'.'), 422);
    }

    /** Revalidate retained typed fields, never an exception message or output. */
    public static function fromResult(mixed $result): self
    {
        if (! is_array($result) || count($result) !== 2 || ($result['schema'] ?? null) !== 1
            || ! is_array($result['error'] ?? null) || count($result['error']) !== 2
            || ! is_string($result['error']['code'] ?? null) || ! array_key_exists('required_bytes', $result['error'])
            || ($result['error']['required_bytes'] !== null && ! is_int($result['error']['required_bytes']))) {
            throw new InvalidArgumentException('Invalid analysis failure code or required size.');
        }

        return new self($result['error']['code'], $result['error']['required_bytes']);
    }
}
