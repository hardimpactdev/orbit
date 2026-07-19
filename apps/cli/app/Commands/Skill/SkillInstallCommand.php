<?php

declare(strict_types=1);

namespace App\Commands\Skill;

use App\Commands\LocalOnlyCommand;
use App\Services\Skill\SkillInstallActions;
use App\Services\Skill\SkillInstallFailure;
use App\Services\Skill\SkillInstallRequest;
use App\Services\Skill\SkillTargetResolution;

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
        $request = new SkillInstallRequest(
            provider: $this->stringArgument('provider'),
            path: $this->stringArgument('path'),
            force: (bool) $this->option('force'),
        );

        $preparation = $actions->prepare($request);

        if ($preparation instanceof SkillInstallFailure) {
            return $this->renderFailure($preparation->code, $preparation->message, $preparation->meta);
        }

        $confirmation = $this->confirmReplacement($actions, $preparation);

        if ($confirmation !== null) {
            return $confirmation;
        }

        if ($actions->requiresReplacement($preparation) && ! $request->force) {
            $request = new SkillInstallRequest(
                provider: $request->provider,
                path: $request->path,
                force: true,
            );
        }

        $result = $actions->install($request);

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

    private function confirmReplacement(
        SkillInstallActions $actions,
        SkillTargetResolution $resolution,
    ): ?int {
        if (! $actions->requiresReplacement($resolution) || $this->option('force') === true) {
            return null;
        }

        $meta = [
            'field' => 'force',
            'reason' => 'destructive_consent_required',
            'target' => $resolution->target,
        ];

        if (! $this->allowsInteractiveInput()) {
            return $this->renderFailure(
                'validation_failed',
                'Use --force to replace the existing skill target.',
                $meta,
            );
        }

        if ($this->confirm("Replace existing Orbit skill target '{$resolution->target}'?", default: false)) {
            return null;
        }

        return $this->renderFailure('validation_failed', 'Operation cancelled.', $meta);
    }
}
