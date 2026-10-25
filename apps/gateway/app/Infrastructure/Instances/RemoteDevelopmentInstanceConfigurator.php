<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\LaravelApplicationKey;
use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Instance;

/**
 * A development Instance's Laravel metadata and URL through the Node agent's `instances.inspect-development-profile`
 * and `instances.write-development-url`, which run as the managed account.
 */
final readonly class RemoteDevelopmentInstanceConfigurator implements DevelopmentInstanceConfigurator, RouteApplicationUrlWriter
{
    public function __construct(
        private AgentCommandRunner $agents,
        private ComposerSourceClassifier $classifier,
    ) {}

    public function inspect(Instance $instance): DevelopmentSourceProfile
    {
        $instance->loadMissing(['project', 'node']);

        // An unrouted monorepo is a source checkout, not a single PHP application.
        if ($instance->project->type === ProjectType::Monorepo && ! $instance->routes()->exists()) {
            return new DevelopmentSourceProfile(null, false);
        }

        $profile = AgentStep::run($this->agents, $instance->node, 'instances.inspect-development-profile', [
            'application' => $instance->applicationDirectory(),
            'owner' => $instance->node->user,
            'entry_point' => $instance->project->type->frameworkEntryPoint(),
        ], 'app-instance-source-classify', 'app-dev.source_classification_failed', 'App development');

        return $this->profile($profile, $instance->project->type);
    }

    public function configureLaravelUrl(Instance $instance, string $url): void
    {
        $this->writeLaravelUrl($instance, $instance->applicationDirectory(), $url, otherApplication: false);
    }

    public function configureDirectoryUrl(Instance $instance, string $relativeDirectory, string $url): void
    {
        $directory = $relativeDirectory === ''
            ? rtrim($instance->checkout_path, '/')
            : rtrim($instance->checkout_path, '/').'/'.RelativeWebRoot::validate($relativeDirectory);
        $this->writeLaravelUrl($instance, $directory, $url, otherApplication: true);
    }

    /**
     * Another application in the checkout keeps its own key and name: a new `.env` takes a generated key,
     * and the agent skips a directory without `artisan`.
     */
    private function writeLaravelUrl(Instance $instance, string $directory, string $url, bool $otherApplication): void
    {
        $instance->loadMissing(['node', 'project']);
        $storedKey = $otherApplication ? null : LaravelApplicationKey::stored($instance);
        $storedName = $instance->environmentValues()->where('env_key', 'APP_NAME')->first()?->env_value;
        $storedName = is_string($storedName) && $storedName !== '' && ! str_contains($storedName, '{{') ? $storedName : null;
        AgentStep::run($this->agents, $instance->node, 'instances.write-development-url', [
            'directory' => $directory,
            'owner' => $instance->node->user,
            'url' => $url,
            'app_key' => 'APP_KEY='.InstanceEnvironmentRenderer::quote($storedKey ?? LaravelApplicationKey::generate()),
            'app_key_stored' => $storedKey !== null,
            'app_name' => 'APP_NAME='.InstanceEnvironmentRenderer::quote($storedName ?? $instance->project->name),
            'app_name_stored' => $storedName !== null,
            'require_artisan' => $otherApplication,
        ], 'laravel-url', 'app-dev.laravel_url_configuration_failed', 'App development');
    }

    /** @param  array<string, mixed>  $result */
    private function profile(array $result, ProjectType $projectType): DevelopmentSourceProfile
    {
        $kind = $result['kind'] ?? null;

        if ($kind === 'none') {
            return new DevelopmentSourceProfile(null, false);
        }

        if ($kind !== 'composer' || ! is_string($result['entry_point'] ?? null)) {
            throw $this->invalid('app-dev.source_metadata_unsafe');
        }

        $json = is_string($result['composer'] ?? null) ? base64_decode($result['composer'], true) : false;

        if (! is_string($json)) {
            throw $this->invalid('app-dev.php_version_unsupported');
        }

        return $this->classifier->classify($json, $projectType, $result['entry_point']);
    }

    private function invalid(string $errorCode, ?\Throwable $previous = null): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException(
            step: 'source-classification',
            errorCode: $errorCode,
            message: 'The development source metadata is invalid or unsupported.',
            previous: $previous,
        );
    }
}
