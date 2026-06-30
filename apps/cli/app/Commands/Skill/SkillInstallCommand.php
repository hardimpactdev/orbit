<?php

declare(strict_types=1);

namespace App\Commands\Skill;

use App\Commands\LocalOnlyCommand;
use App\Services\Skill\SkillInstallActions;
use App\Services\Skill\SkillInstallFailure;
use App\Services\Skill\SkillInstallRequest;

final class SkillInstallCommand extends LocalOnlyCommand
{
    #[\Override]
    protected $signature = 'skill:install
        {provider? : Provider slug (codex, claude, antigravity, grok) or explicit target path}
        {path? : Explicit target path when provider is a known slug}
        {--force : Overwrite an existing target without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Install the repository-owned Orbit skill for a provider or explicit path.';

    public function handle(SkillInstallActions $actions): int
    {
        $result = $actions->install(new SkillInstallRequest(
            provider: $this->stringArgument('provider'),
            path: $this->stringArgument('path'),
            force: (bool) $this->option('force'),
        ));

        if ($result instanceof SkillInstallFailure) {
            return $this->renderFailure($result->code, $result->message, $result->meta);
        }

        return $this->renderSuccess($result->data());
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
