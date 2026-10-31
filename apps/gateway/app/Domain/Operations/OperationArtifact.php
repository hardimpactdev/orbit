<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;

/** A bounded existing artifact. References never carry local paths or inline file bytes. */
final readonly class OperationArtifact
{
    public const int MaximumBytes = 268435456;

    public int $bytes;

    public function __construct(public string $path, public string $sha256)
    {
        if (str_contains($path, "\0")) {
            $this->refuse();
        }
        $stat = false;
        $current = '';
        foreach (explode('/', $path) as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/'.$part;
            $stat = @lstat($current);
            if (in_array($part, ['.', '..'], true) || $stat === false || ($stat['mode'] & 0170000) === 0120000) {
                $this->refuse();
            }
        }
        if (! str_starts_with($path, '/') || $stat === false || ($stat['mode'] & 0170000) !== 0100000
            || $stat['size'] < 1 || $stat['size'] > self::MaximumBytes || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || @hash_file('sha256', $path) !== $sha256) {
            $this->refuse();
        }
        $this->bytes = $stat['size'];
    }

    /** @return array{path:string,sha256:string,bytes:int,max_bytes:int} */
    public function reference(int $operationId, string $runId, string $field): array
    {
        return ['path' => '/api/v1/direct-operations/'.$operationId.'/private-material?'.http_build_query(['run_id' => $runId, 'artifact' => $field], encoding_type: PHP_QUERY_RFC3986),
            'sha256' => $this->sha256, 'bytes' => $this->bytes, 'max_bytes' => self::MaximumBytes];
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.artifact_unavailable', 'The admitted artifact must be a bounded regular file matching its digest.', 409);
    }
}
