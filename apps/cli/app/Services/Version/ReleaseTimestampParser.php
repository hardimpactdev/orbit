<?php

declare(strict_types=1);

namespace App\Services\Version;

use Carbon\CarbonImmutable;
use Throwable;

final readonly class ReleaseTimestampParser
{
    public function parse(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $manifest
     */
    public function parseTopologyCandidateBuildId(array $manifest): ?string
    {
        if (($manifest['source'] ?? null) !== 'topology-candidate') {
            return null;
        }

        $buildId = $manifest['build_id'] ?? null;

        if (! is_string($buildId)) {
            return null;
        }

        if (! preg_match(
            '/^(?<date>\d{4}-?\d{2}-?\d{2})T(?<hour>\d{2})(?<minute>\d{2})(?<second>\d{2})Z(?:-|$)/',
            $buildId,
            $matches,
        )) {
            return null;
        }

        $date = str_replace(search: '-', replace: '', subject: $matches['date']);
        $date = substr($date, offset: 0, length: 4)
        .'-'
        .substr($date, offset: 4, length: 2)
        .'-'
        .substr(
            $date,
            offset: 6,
            length: 2,
        );

        return $this->parse("{$date}T{$matches['hour']}:{$matches['minute']}:{$matches['second']}Z");
    }
}
