<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Actions\Fleet\MergeNightlyRecipePullRequestsAction;
use App\Actions\Fleet\NightlyRecipeContents;
use App\Actions\Fleet\OpenNightlyRecipePullRequestAction;
use App\Actions\Fleet\OpenRecipeArtifactsPullRequestAction;
use App\Actions\Fleet\RefreshNightlyRecipePullRequestAction;
use App\Actions\Fleet\RetireNightlyRecipePullRequestsAction;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/** A bounded GitHub effect on the fixed Orbit repository. Credentials never leave the Gateway. */
final readonly class RecipePrCommand implements OperationGatewayCommand
{
    public function __construct(private OpenNightlyRecipePullRequestAction $snapshot, private OpenRecipeArtifactsPullRequestAction $artifacts,
        private RefreshNightlyRecipePullRequestAction $refresh, private MergeNightlyRecipePullRequestsAction $merge,
        private RetireNightlyRecipePullRequestsAction $retire, private GitHubApi $github, private GitHubAppStore $store, private GatewayServingHost $gateway) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'action' => ['type' => 'string', 'enum' => ['open', 'update', 'merge', 'retire']],
            'variant' => ['type' => 'string', 'enum' => ['snapshot', 'artifacts']],
            'date' => ['type' => 'string', 'pattern' => '^[0-9]{8}$'],
            'pull_number' => ['type' => 'integer', 'minimum' => 0],
            'head_sha' => ['type' => 'string'], 'token' => ['type' => 'string', 'minLength' => 1],
        ], 'required' => ['action', 'variant', 'date', 'pull_number', 'head_sha', 'token'], 'additionalProperties' => false];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $node = Node::query()->findOrFail($operation->node_id);
        if (! $this->gateway->is($node) || ($input['date'] ?? null) !== now('Europe/Amsterdam')->format('Ymd')) {
            return ['error_code' => 'fleet.recipe_pr_stale'];
        }
        $token = ValidatedData::string($input['token']);
        $repository = GitHubRepository::fromOrigin('https://github.com/hardimpactdev/orbit');
        if ($repository === null) {
            throw new ResourceOperationException('fleet.repository_invalid', 'The recipe repository is invalid.', 422);
        }
        $number = ValidatedData::integer($input['pull_number']);
        $action = ValidatedData::string($input['action']);
        $url = null;
        try {
            if ($action === 'open') {
                $url = $input['variant'] === 'snapshot' ? $this->snapshot->execute($token) : $this->artifacts->execute($token);
            } elseif ($action === 'merge') {
                $this->merge->execute($number, $token, ValidatedData::string($input['head_sha']));
            } else {
                $http = Http::baseUrl('https://api.github.com/repos/hardimpactdev/orbit')->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5);
                $content = app(NightlyRecipeContents::class)->read($http, 'main');
                $author = $this->store->credentials()?->slug.'[bot]';
                $pulls = $this->github->openPullRequests($token, $repository);
                $pull = array_find($pulls, static fn ($pull): bool => $pull->number === $number && $pull->headSha === $input['head_sha']);
                if ($pull === null) {
                    return ['completed' => true, 'outcome' => 'absent'];
                }
                if ($action === 'retire') {
                    $this->retire->execute($http, $repository, $pulls, $author, $content, $number);
                } else {
                    $head = $http->get('/git/ref/heads/main')->throw()->json('object.sha');
                    $this->refresh->execute(ValidatedData::string($head), $content, $number, $token, $pull->headSha);
                }
            }
        } catch (RecipePrFailure $failure) {
            return $failure->retryable ? ['error_code' => 'fleet.recipe_pr_retryable'] : ['completed' => true, 'outcome' => 'refused'];
        } catch (NightlyRecipePendingDeployment) {
            return ['error_code' => 'fleet.recipe_pending_deployment'];
        }

        return ['completed' => true, 'outcome' => 'checked', 'url' => $url];
    }
}
