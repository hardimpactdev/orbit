<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\LaravelApplicationKey;
use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Instance;

/**
 * Writes `APP_URL` into the stable `.env` of another application directory of a production Instance,
 * `<home>/env/<directory>/.env`, which each release links to. It reads `artisan` and `.env.example`
 * from the selected release, and does nothing before the first release or for a directory without
 * `artisan`. A new file gets its own key. A cached configuration in a release keeps its value until
 * the next deployment rebuilds it. The Node agent's `instances.write-directory-url` writes the file as the production
 * user.
 */
final readonly class RemoteProductionRouteApplicationUrlWriter implements RouteApplicationUrlWriter
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function configureDirectoryUrl(Instance $instance, string $relativeDirectory, string $url): void
    {
        $instance->loadMissing(['node', 'project']);
        $user = $instance->production_user;
        $home = $instance->production_home;

        if (! $instance->placedOnAppProd() || ! is_string($user) || ! is_string($home) || $home !== "/home/{$user}"
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1) {
            throw new ResourceOperationException('app-prod.web_root_invalid', 'The production Instance identity is incomplete.', 409);
        }

        $directory = $relativeDirectory === '' ? '' : RelativeWebRoot::validate($relativeDirectory);
        $storedName = $instance->environmentValues()->where('env_key', 'APP_NAME')->first()?->env_value;
        $storedName = is_string($storedName) && $storedName !== '' && ! str_contains($storedName, '{{') ? $storedName : null;
        AgentStep::run($this->agents, $instance->node, 'instances.write-directory-url', [
            'user' => $user,
            'home' => $home,
            'directory' => $directory,
            'url' => $url,
            'app_key' => 'APP_KEY='.InstanceEnvironmentRenderer::quote(LaravelApplicationKey::generate()),
            'app_name' => 'APP_NAME='.InstanceEnvironmentRenderer::quote($storedName ?? $instance->project->name),
            'app_name_stored' => $storedName !== null,
        ], 'app-prod-laravel-url', 'app-prod.laravel_url_configuration_failed', 'App production');
    }
}
