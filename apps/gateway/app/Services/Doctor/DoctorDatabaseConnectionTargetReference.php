<?php

declare(strict_types=1);

namespace App\Services\Doctor;

final readonly class DoctorDatabaseConnectionTargetReference
{
    private function __construct(
        public string $type,
        public int $id,
        public string $envPrefix,
        public ?int $databaseConnectionId,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     */
    public static function fromDetail(array $detail): ?self
    {
        $type = is_string($detail['target_type'] ?? null) ? $detail['target_type'] : null;
        $id = self::integerValue($detail['target_id'] ?? null);
        $envPrefix = is_string($detail['env_prefix'] ?? null) ? $detail['env_prefix'] : null;

        if (! in_array($type, ['instance', 'workspace'], strict: true) || $id === null || $envPrefix === null) {
            return null;
        }

        return new self(
            type: $type,
            id: $id,
            envPrefix: $envPrefix,
            databaseConnectionId: self::integerValue($detail['database_connection_id'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public static function detailTargetsWorkspace(array $detail): bool
    {
        return ($detail['target_type'] ?? null) === 'workspace' || is_string($detail['workspace'] ?? null);
    }

    private static function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
