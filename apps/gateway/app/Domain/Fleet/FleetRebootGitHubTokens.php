<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\GitHub\GitHubAccessToken;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\GitHub\NativeGitHubTokenIssuer;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The GitHub tokens of a `fleet.reboot` pass (ADR 0251): one `actions: write` token per admitted CI repository, which the
 * `rerun` phase uses to re-run the jobs a runner lost when the drain stopped it. The App already holds `Actions: write`; the
 * pass needs no runner administration (ADR 0223). Only the Swamp Node may mint, only for the pass's bound run, and only for
 * the repositories that admission froze in the intent.
 */
final readonly class FleetRebootGitHubTokens implements NativeGitHubTokenIssuer
{
    public function __construct(private DirectOperation $direct, private GitHubAppStore $store, private SwampCommandStep $step) {}

    /** @return array{vault: string, key: string} */
    public static function reference(Operation $operation): array
    {
        return ['vault' => 'orbit-control-inputs', 'key' => 'fleet-reboot-github-operation-'.$operation->id];
    }

    public function issue(Operation $operation, int $callerNodeId, string $runId, ?string $purpose = null): array
    {
        $operation->refresh();
        $this->direct->assertRun($operation, $runId);
        if ($purpose !== null || $operation->kind !== FleetReboot::Kind || $callerNodeId !== SwampNode::id() || $operation->status->terminal()
            || $operation->cancel_requested_at !== null) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'Only the running fleet reboot pass may mint its Actions tokens.', 403);
        }
        $credentials = $this->store->credentials();
        $github = app(GitHubApi::class);
        // A repository the App is not installed on gets no token; its jobs cannot be re-run.
        $minted = [];
        try {
            foreach (ValidatedData::stringList(ValidatedData::object($operation->input['intent'] ?? [])['ci_repositories'] ?? []) as $slug) {
                $repository = GitHubRepository::fromOrigin('https://github.com/'.$slug);
                if ($repository === null || $credentials === null || $github->repositoryInstallation($credentials, $repository) === null) {
                    continue;
                }
                $minted[$slug] = $github->scopedRepositoryToken($credentials,
                    GitHubTokenGrant::from(['repository' => $slug, 'permissions' => ['actions' => 'write']]));
            }
            $reference = self::reference($operation);
            $tokens = array_map(static fn (GitHubAccessToken $token): string => $token->reveal(), $minted);

            return ['github_actions' => $this->step->executionCopy($reference['vault'], $reference['key'], json_encode((object) $tokens, JSON_THROW_ON_ERROR))];
        } catch (Throwable $exception) {
            foreach ($minted as $token) {
                if (! $github->revokeInstallationToken($token)) {
                    Log::warning('operation.github_token_revoke_failed');
                }
            }
            throw $exception;
        }
    }
}
