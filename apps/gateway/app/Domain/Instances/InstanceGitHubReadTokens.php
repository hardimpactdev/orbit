<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\GitHub\GitHubAccessToken;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\GitHub\NativeGitHubTokenIssuer;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\DirectOperation;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\GitHub\GitHubTokenCache;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;

/** Owner-checked read scope at the existing numeric Operation mint primitive. */
final readonly class InstanceGitHubReadTokens implements NativeGitHubTokenIssuer
{
    public function __construct(
        private DirectOperation $direct,
        private GitHubAppStore $store,
        private HttpGitHubApi $github,
        private SwampCommandStep $step,
        private GitHubTokenCache $tokens,
    ) {}

    /** @return array{vault:string,key:string} */
    public static function reference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-git-operation-'.$operation->id];
    }

    /** The same token as one git credential-store line, for a fetch that reads a 0600 credential file.
     * @return array{vault:string,key:string}
     */
    public static function credentialReference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-git-credential-'.$operation->id];
    }

    /**
     * A clone's credential line with the private text that its `clone-container` stdin carries beside it, in one copy: a step's stdin is
     * one whole vault value (docs/reference/swamp-command-steps.md#secret-inputs).
     *
     * @return array{vault:string,key:string}
     */
    public static function cloneReference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'instance-create-clone-'.$operation->id];
    }

    public function issue(Operation $operation, int $callerNodeId, string $runId, ?string $purpose = null): array
    {
        if ($purpose !== null) {
            throw $this->forbidden();
        }
        $authority = $this->authorize($operation, $callerNodeId, $runId);

        return ['git_read' => $this->copy($operation, $authority['repository'],
            fn () => $this->authorize($operation, $callerNodeId, $runId) === $authority)];
    }

    /**
     * The admitted repository's read credential as one git credential-store line, for an engine step that carries it as a blob to
     * the Instance's Node alone. Reuses the repository's scope-verified token from {@see GitHubTokenCache} so repeated creates
     * do not stall the engine consumer on GitHub HTTPS calls. A public repository gets an empty line and fetches anonymously.
     */
    public function line(Operation $operation): string
    {
        $authority = $this->repository($operation);
        $token = $this->cachedReadToken($authority['repository']);

        return $token === null ? '' : 'https://x-access-token:'.$token."@github.com\n";
    }

    /** Drops any cached installation and scoped read token for the Operation's repository, so the next fetch mints fresh ones. */
    public function forget(Operation $operation): void
    {
        $origin = $operation->input['repository'] ?? null;
        $repository = is_string($origin) ? GitHubRepository::fromOrigin($origin) : null;
        if ($repository instanceof GitHubRepository) {
            $this->tokens->forget($repository);
        }
    }

    /**
     * Development create and a normal removal mint when their run starts, as the legacy Gateway did before its source call.
     * An already active create runs no source phase and gets no credential.
     *
     * @param  array<string,mixed>|null  $stage
     * @return array{vault:string,key:string}
     */
    public function start(Operation $operation, string $beside = '', ?array $stage = null): array
    {
        if ((ValidatedData::object($operation->input['direct_provisioning'] ?? [])['already_active'] ?? false) === true) {
            $reference = self::reference($operation);

            return $this->step->executionCopy($reference['vault'], $reference['key'], '{"github_token":null}');
        }
        $authority = $this->repository($operation);

        return $this->copy($operation, $authority['repository'], fn (): bool => $this->repository($operation->refresh()) === $authority, $beside, $stage);
    }

    /**
     * One git credential-store line under `$key`, for a fetch that reads a 0600 credential file. Only a confirmed
     * public GitHub repository gets an empty line and fetches anonymously.
     *
     * @param  array<string,mixed>|null  $release  Other private fields sent with the credential in one stdin value.
     * @return array{vault:string,key:string}
     */
    public function credentialCopy(string $origin, string $key, #[\SensitiveParameter] ?array $release = null): array
    {
        $credential = $this->mint($origin);
        try {
            $token = $credential?->reveal();

            $line = $token === null ? '' : 'https://x-access-token:'.$token."@github.com\n";

            return $this->step->executionCopy('orbit-control-inputs', $key, $release === null ? $line : json_encode([...$release, 'credential' => $line], JSON_THROW_ON_ERROR));
        } catch (\Throwable $exception) {
            if ($credential !== null && ! $this->github->revokeInstallationToken($credential)) {
                Log::warning('operation.github_token_revoke_failed');
            }
            throw $exception;
        }
    }

    /** Safe diagnosis shared by credential preparation and the Node's later authentication refusal. */
    public static function accessDeniedMessage(string $app, GitHubRepository $repository): string
    {
        return "The GitHub App {$app} cannot read {$repository->owner}/{$repository->name}: install it for that organisation or add the repository.";
    }

    /** A fresh Contents read token, or anonymous access for a confirmed public repository. */
    private function mint(string $origin): ?GitHubAccessToken
    {
        $repository = GitHubRepository::fromOrigin($origin);
        if ($repository === null) {
            return null;
        }
        $name = $repository->owner.'/'.$repository->name;
        try {
            $credentials = $this->store->credentials();
            $app = $credentials->slug ?? '(not configured)';
            if ($credentials !== null && ($installationId = $this->github->repositoryInstallation($credentials, $repository)) !== null) {
                try {
                    return $this->github->scopedRepositoryToken($credentials,
                        GitHubTokenGrant::from(['repository' => $name, 'permissions' => ['contents' => 'read']]),
                        $installationId);
                } catch (ResourceOperationException $exception) {
                    if ($exception->errorCode !== 'dependency_token.refused') {
                        throw $exception;
                    }
                    // An installation may exist without covering this repository or the requested permission.
                }
            }
            if ($this->github->repositoryIsPublic($repository)) {
                return null;
            }
        } catch (\Throwable $exception) {
            throw new ResourceOperationException('source.github_unavailable',
                "GitHub could not supply a source credential for {$name}.", 502, $exception);
        }

        throw new ResourceOperationException('source.github_app_denied',
            self::accessDeniedMessage($app, $repository), 403);
    }

    /** A scope-verified Contents read token from {@see GitHubTokenCache}, or anonymous access for a confirmed public repository. */
    private function cachedReadToken(string $origin): ?string
    {
        $repository = GitHubRepository::fromOrigin($origin);
        if ($repository === null) {
            return null;
        }
        $name = $repository->owner.'/'.$repository->name;
        try {
            $credentials = $this->store->credentials();
            $app = $credentials->slug ?? '(not configured)';
            if ($credentials !== null) {
                $token = $this->cachedAppReadToken($credentials, $repository);
                if ($token !== null) {
                    return $token;
                }
            }
            if ($this->github->repositoryIsPublic($repository)) {
                return null;
            }
        } catch (\Throwable $exception) {
            throw new ResourceOperationException('source.github_unavailable',
                "GitHub could not supply a source credential for {$name}.", 502, $exception);
        }

        throw new ResourceOperationException('source.github_app_denied',
            self::accessDeniedMessage($app, $repository), 403);
    }

    private function cachedAppReadToken(GitHubAppCredentials $credentials, GitHubRepository $repository): ?string
    {
        $name = $repository->owner.'/'.$repository->name;
        $grant = GitHubTokenGrant::from(['repository' => $name, 'permissions' => ['contents' => 'read']]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $resolvedInstallation = false;
            $installationId = $this->tokens->installation($credentials->appId, $repository, function () use ($credentials, $repository, &$resolvedInstallation): ?int {
                $resolvedInstallation = true;

                return $this->github->repositoryInstallation($credentials, $repository);
            });
            if ($installationId === null) {
                return null;
            }
            try {
                return $this->tokens->token(
                    $credentials->appId,
                    $installationId,
                    $repository,
                    GitHubTokenCache::ScopedReadPermission,
                    fn (): string => $this->github->scopedRepositoryToken($credentials, $grant, $installationId)->reveal(),
                );
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'dependency_token.refused') {
                    throw $exception;
                }
                $this->tokens->forget($repository);
                if ($resolvedInstallation) {
                    // A fresh installation lookup was refused: the App does not cover this repository or permission.
                    return null;
                }
                // A cached installation may have gone stale after a reinstall; try once with a fresh lookup.
            }
        }

        return null;
    }

    /**
     * `$beside`, when given, is the private text of a clone's `clone-container` stdin, which its one credential copy holds after the line.
     * `$stage`, when given, is a clone's public stage (CloneStage::payload): its one-line launch copy holds it beside the clone copy's
     * text.
     *
     * @param  \Closure(): bool  $unchanged
     * @param  array<string,mixed>|null  $stage
     * @return array{vault:string,key:string}
     */
    private function copy(Operation $operation, string $origin, \Closure $unchanged, string $beside = '', ?array $stage = null): array
    {
        $credential = $this->mint($origin);
        try {
            if (! $unchanged()) {
                throw new ResourceOperationException('operation.github_token_forbidden', 'The admitted repository changed during minting.', 409);
            }
            $reference = self::reference($operation);
            $line = self::credentialReference($operation);
            $token = $credential?->reveal();
            $copy = $this->step->executionCopy($reference['vault'], $reference['key'], json_encode(['github_token' => $token], JSON_THROW_ON_ERROR));
            // A clone's run reads only the framed copy, so the line goes into that one alone.
            $framed = $beside === '' ? $line : self::cloneReference($operation);
            $private = ($token === null ? '' : 'https://x-access-token:'.$token."@github.com\n").$beside;
            $this->step->executionCopy($framed['vault'], $framed['key'], $private);
            if ($stage !== null) {
                $launch = CloneStage::reference($operation);
                $this->step->executionCopy($launch['vault'], $launch['key'], CloneStage::value($stage, $private));
            }

            return $copy;
        } catch (\Throwable $exception) {
            if ($credential !== null && ! $this->github->revokeInstallationToken($credential)) {
                Log::warning('operation.github_token_revoke_failed');
            }
            throw $exception;
        }
    }

    /** @return array{kind:string,repository:string,repository_identity:string,source_access:string} */
    private function authorize(Operation $operation, int $callerNodeId, string $runId): array
    {
        $operation->refresh();
        $this->direct->assertRun($operation, $runId);
        if ($callerNodeId !== SwampNode::id() || $operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw $this->forbidden();
        }

        return $this->repository($operation);
    }

    /** @return array{kind:string,repository:string,repository_identity:string,source_access:string} */
    private function repository(Operation $operation): array
    {
        $input = $operation->input;
        $project = Project::query()->find($input['project_id'] ?? null);
        $instance = Instance::query()->find($operation->target_id);
        if ($operation->target_type !== 'instance' || ! $project instanceof Project || ! $instance instanceof Instance
            || $instance->id !== ($input['instance_id'] ?? null) || $instance->project_id !== $project->id
            || ! ($instance->placedOnAppDev() || ($operation->kind === 'instance.clone' && $instance->placedOnAppProd())) || $instance->node_id !== $operation->node_id || ($input['repository'] ?? null) !== $project->repository_url
            || ($input['repository_identity'] ?? null) !== $project->repository_identity
            || GitRepositoryIdentity::derive($project->repository_url) !== $project->repository_identity
            || $project->source_access === ProjectSourceAccess::GhCli) {
            throw $this->forbidden();
        }

        return ['kind' => $operation->kind, 'repository' => $project->repository_url,
            'repository_identity' => $project->repository_identity, 'source_access' => $project->source_access->value];
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.github_token_forbidden', 'Only the admitted Instance repository can be read with scoped App access.', 403);
    }
}
