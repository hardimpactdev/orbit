<?php

declare(strict_types=1);

namespace App\Services\Doctor;

final readonly class DoctorPublicVocabulary
{
    /**
     * @param  list<string>  $families
     * @return list<string>
     */
    public function internalFamilies(array $families): array
    {
        return array_map(
            static fn (string $family): string => $family === 'instance' ? 'app' : $family,
            $families,
        );
    }

    public function internalKey(?string $key): ?string
    {
        if (! is_string($key)) {
            return null;
        }

        return str_starts_with($key, 'instance.') ? 'app.'.substr($key, 9) : $key;
    }

    public function publicFamily(string $family): string
    {
        return $family === 'app' ? 'instance' : $family;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function publicReport(array $report): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = $this->publicValue($report);

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>|null  $issues
     * @return list<array<string, mixed>>|null
     */
    public function internalIssues(?array $issues): ?array
    {
        if ($issues === null) {
            return null;
        }

        return array_map(
            function (array $issue): array {
                /** @var array<string, mixed> $normalized */
                $normalized = $this->internalValue($issue);

                return $normalized;
            },
            $issues,
        );
    }

    private function publicValue(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $childKey => $childValue) {
                $publicKey = match ($childKey) {
                    'app' => 'project',
                    'app_instance' => 'instance',
                    default => $childKey,
                };
                $normalized[$publicKey] = $this->publicValue(
                    $childValue,
                    $key === 'families' ? 'families' : (is_string($publicKey) ? $publicKey : null),
                );
            }

            return $normalized;
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($key === 'family') {
            return $this->publicFamily($value);
        }

        if ($key === 'families') {
            return $this->publicFamily($value);
        }

        if (in_array($key, ['key', 'code'], true) && str_starts_with($value, 'app.')) {
            return 'instance.'.substr($value, 4);
        }

        if (in_array($key, ['scope', 'target_type', 'owner_type', 'type'], true)) {
            return match ($value) {
                'app' => 'project',
                'app_instance' => 'instance',
                default => $value,
            };
        }

        return $value;
    }

    private function internalValue(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $childKey => $childValue) {
                $internalKey = match ($childKey) {
                    'project' => 'app',
                    'instance' => 'app_instance',
                    default => $childKey,
                };
                $normalized[$internalKey] = $this->internalValue(
                    $childValue,
                    is_string($internalKey) ? $internalKey : null,
                );
            }

            return $normalized;
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($key === 'family') {
            return $value === 'instance' ? 'app' : $value;
        }

        if (in_array($key, ['key', 'code'], true) && str_starts_with($value, 'instance.')) {
            return 'app.'.substr($value, 9);
        }

        if (in_array($key, ['scope', 'target_type', 'owner_type', 'type'], true)) {
            return match ($value) {
                'project' => 'app',
                'instance' => 'app_instance',
                default => $value,
            };
        }

        return $value;
    }
}
