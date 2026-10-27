<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Models\Task;
use Carbon\CarbonImmutable;

/** The request identity and messages shared by reader expiry checks and successful installation. */
final readonly class SwampReaderAssistance
{
    private const string UnknownExpiry = 'The Gateway Swamp reader expiry is unknown. Install a replacement with recorded expiry.';

    private const string ExpiryPrefix = 'The Gateway Swamp reader expires at ';

    private const string ExpirySuffix = '. Rotate it before expiry.';

    public function __construct(private SettingRepository $settings) {}

    public static function key(#[\SensitiveParameter] string $token): string
    {
        return 'swamp.reader_assistance.'.hash('sha256', $token);
    }

    public static function reason(?CarbonImmutable $expiry): string
    {
        return $expiry === null ? self::UnknownExpiry : self::ExpiryPrefix.$expiry->toISOString().self::ExpirySuffix;
    }

    /** Called in the installation transaction, with the Gateway release lock held. */
    public function clear(#[\SensitiveParameter] string $token): void
    {
        if ($token === '') {
            return;
        }
        $id = $this->settings->get(new SettingScope(SettingScopeType::Gateway), self::key($token));
        $group = $id === null ? null : Task::topLevel()->lockForUpdate()->find((int) $id);
        if (! $group instanceof Task || ! $group->assistance_requested
            || $group->assistance_kind !== AssistanceKind::Direction
            || $group->assistance_question !== $group->assistance_reason
            || ! $this->ownsReason($group->assistance_reason ?? '')) {
            return;
        }
        TaskAssistance::apply($group);
    }

    private function ownsReason(string $reason): bool
    {
        if ($reason === self::UnknownExpiry) {
            return true;
        }
        if (! str_starts_with($reason, self::ExpiryPrefix) || ! str_ends_with($reason, self::ExpirySuffix)) {
            return false;
        }
        $value = substr($reason, strlen(self::ExpiryPrefix), -strlen(self::ExpirySuffix));
        $expiry = SwampReaderCredential::expiry($value);

        return $expiry !== null && $reason === self::reason($expiry);
    }
}
