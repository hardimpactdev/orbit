<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Instances\PublishInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class PublishCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:publish
        {instance : Numeric instance ID}
        {--commit= : Full commit SHA in the Instance checkout}
        {--branch= : Target branch}
        {--base= : Pull request base branch}
        {--title= : Pull request title}
        {--body= : Pull request body}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Publish a development Instance commit through the GitHub App.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($instanceId === null) {
            return self::FAILURE;
        }
        $commit = $this->stringOption('commit');
        $branch = $this->stringOption('branch');
        if ($this->consoleMode()->mayPrompt) {
            $commit ??= $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(label: 'Commit SHA', required: true));
            $branch ??= $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(label: 'Target branch', required: true));
        }
        if (! is_string($commit) || ! is_string($branch)) {
            return $this->renderGatewayFailure('validation.failed', 'Supply --commit and --branch.');
        }
        $pr = null;
        $base = $this->option('base');
        $title = $this->option('title');
        $body = $this->option('body');
        if ($base !== null || $title !== null || $body !== null) {
            if (! is_string($base) || ! is_string($title) || ! is_string($body)) {
                return $this->renderGatewayFailure('validation.failed', 'Supply --base, --title, and --body together.');
            }
            $pr = ['base' => $base, 'title' => $title, 'body' => $body];
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $connector = $connectors->make($profile);
        $key = $this->option('idempotency-key');
        $operation = $this->sendWithProgress($connector, new PublishInstanceRequest($instanceId, $commit, $branch, $pr, is_string($key) ? $key : null), OperationResponse::class, ['Start publication', 'Starting publication', 'Started publication']);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Publish Instance [{$instanceId}]", 'Publication')
            : self::FAILURE;
    }
}
