<?php

declare(strict_types=1);

namespace App\Services\Skill;

enum SkillProvider: string
{
    case Codex = 'codex';
    case Claude = 'claude';
    case Antigravity = 'antigravity';
    case Grok = 'grok';

    public static function tryFromSlug(string $slug): ?self
    {
        return self::tryFrom($slug);
    }

    public function defaultTargetPath(string $home): string
    {
        $home = rtrim(string: $home, characters: '/');

        return match ($this) {
            self::Codex => "{$home}/.agents/skills/orbit",
            self::Claude => "{$home}/.claude/skills/orbit",
            self::Antigravity => "{$home}/.gemini/config/skills/orbit",
            self::Grok => "{$home}/.grok/skills/orbit",
        };
    }
}
