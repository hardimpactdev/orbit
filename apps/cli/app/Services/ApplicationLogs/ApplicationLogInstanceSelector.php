<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Canonical public instance selector: exactly one lowercase dotted pair `app.instance`.
 */
final readonly class ApplicationLogInstanceSelector
{
    private const string Pattern = '/\A[a-z0-9-]+\.[a-z0-9-]+\z/';

    /**
     * @return array{ok: true, selector: string}|array{ok: false, field: string, message: string}
     */
    public function parse(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [
                'ok' => false,
                'field' => 'instance',
                'message' => 'An instance selector is required.',
            ];
        }

        if (preg_match(self::Pattern, $value) !== 1) {
            return [
                'ok' => false,
                'field' => 'instance',
                'message' => 'Instance selectors must be lowercase app.instance with exactly one dot.',
            ];
        }

        return ['ok' => true, 'selector' => $value];
    }
}
