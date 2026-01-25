<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Enums;

/**
 * Represents the user's intent for repository handling during project creation.
 */
enum RepoIntent: string
{
    case ComposerCreate = 'composer';
    case Clone = 'clone';
    case Fork = 'fork';
    case Template = 'template';
    case None = 'none';

    /**
     * Determine intent from API payload.
     */
    public static function fromPayload(array $payload): self
    {
        return match (true) {
            ! empty($payload['package']) => self::ComposerCreate,
            ! empty($payload['is_template']) && ! empty($payload['template']) => self::Template,
            ! empty($payload['fork']) => self::Fork,
            ! empty($payload['clone_url']) || ! empty($payload['template']) => self::Clone,
            default => self::None,
        };
    }

    /**
     * Whether this intent requires cloning a repository.
     */
    public function requiresClone(): bool
    {
        return in_array($this, [self::Clone, self::Fork, self::Template], true);
    }

    /**
     * Whether this intent creates a new repository on GitHub.
     */
    public function requiresRepoCreation(): bool
    {
        return in_array($this, [self::Fork, self::Template], true);
    }
}
